<?php

declare(strict_types=1);

namespace App\Ponto\Repository;

use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\ConfiguracaoDoPonto;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * A configuração do Ponto por escritório (E7). Toda consulta recebe o escritório: nenhuma lê a de outro.
 *
 * @extends ServiceEntityRepository<ConfiguracaoDoPonto>
 */
// Não-final: o teste do UseCase mocka o repositório do domínio (skill criar-repository).
class ConfiguracaoDoPontoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConfiguracaoDoPonto::class);
    }

    /** A configuração do escritório, ou null se ele nunca salvou (= os padrões, tudo ligado). */
    public function doTenant(Tenant $tenant): ?ConfiguracaoDoPonto
    {
        return $this->findOneBy(['tenant' => $tenant]);
    }

    /**
     * A linha do escritório, garantida e travada até o fim da transação. Só dentro de {@see emTransacao()}.
     *
     * Primeiro garante a linha com `INSERT ... ON CONFLICT (tenant_id) DO NOTHING`, com os padrões da entidade (os
     * mesmos da migration Version20261109180000) e o mapa de chaves vazio (tudo ligado, P96): sem linha, o
     * SELECT ... FOR UPDATE não trava nada, e dois primeiros salvamentos ao mesmo tempo criariam duas linhas (o
     * segundo bateria em `uniq_ponto_config_escritorio_tenant` e fecharia o EntityManager). Com a linha garantida, o
     * banco decide a corrida: o segundo INSERT espera o primeiro e não faz nada.
     *
     * Depois trava a linha (SELECT ... FOR UPDATE): dois administradores salvando ao mesmo tempo não apagam a mudança
     * um do outro no mapa de chaves. Relê do banco mesmo que a linha já esteja carregada nesta requisição.
     *
     * A criação é SQL direto, fora do ORM: ela não vai para o `audit_log`, e a linha vazia vale o mesmo que não ter
     * linha. A mudança que vier depois, pelo ORM, vai.
     */
    public function garantidaETravada(Tenant $tenant, \DateTimeImmutable $agora): ConfiguracaoDoPonto
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO ponto_config_escritorio (tenant_id, chaves, raio_padrao_metros, banco_limite_horas, banco_prazo_meses, permite_trabalho_externo, atualizado_em)
             VALUES (:tenant, :chaves, :raio, :limite, :prazo, :externo, :agora)
             ON CONFLICT (tenant_id) DO NOTHING',
            [
                'tenant'  => $tenant->getId(),
                'chaves'  => '[]',
                'raio'    => ConfiguracaoDoPonto::RAIO_PADRAO_METROS,
                'limite'  => ConfiguracaoDoPonto::BANCO_LIMITE_HORAS_PADRAO,
                'prazo'   => ConfiguracaoDoPonto::BANCO_PRAZO_MESES_PADRAO,
                'externo' => ConfiguracaoDoPonto::PERMITE_TRABALHO_EXTERNO_PADRAO,
                'agora'   => $agora,
            ],
            ['externo' => Types::BOOLEAN, 'agora' => Types::DATETIME_IMMUTABLE],
        );

        $resultado = $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
        if (!$resultado instanceof ConfiguracaoDoPonto) {
            throw new \LogicException('A configuração do Ponto do escritório deveria existir depois do INSERT ... ON CONFLICT.');
        }

        return $resultado;
    }

    /**
     * @template T
     *
     * @param \Closure(): T $trabalho
     *
     * @return T
     */
    public function emTransacao(\Closure $trabalho): mixed
    {
        return $this->getEntityManager()->wrapInTransaction(static fn (): mixed => $trabalho());
    }

    public function salvar(ConfiguracaoDoPonto $configuracao, bool $flush = false): void
    {
        $this->getEntityManager()->persist($configuracao);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
