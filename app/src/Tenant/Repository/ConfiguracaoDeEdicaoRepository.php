<?php

declare(strict_types=1);

namespace App\Tenant\Repository;

use App\Entity\Tenant\Tenant;
use App\Tenant\Entity\ConfiguracaoDeEdicao;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * A opção de edição sem prazo do primário, por escritório. Tenant explícito em toda consulta.
 *
 * @extends ServiceEntityRepository<ConfiguracaoDeEdicao>
 */
class ConfiguracaoDeEdicaoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConfiguracaoDeEdicao::class);
    }

    public function doEscritorio(Tenant $tenant): ?ConfiguracaoDeEdicao
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Sem linha, a opção vale ligada (spec §3). Lida pelo DBAL, sem o TenantFilter da sessão: o escritório pedido
     * pode não ser o da sessão (item 8, o primário do escritório DONO da demanda do TE), e o filtro esconderia a
     * linha, caindo no "ligada" (falha aberta). O tenant vai explícito no WHERE.
     */
    public function primarioSemPrazo(Tenant $tenant): bool
    {
        $valor = $this->getEntityManager()->getConnection()->fetchOne(
            // 1/0 em vez do booleano: o `false` do fetchOne é "sem linha", não pode ser confundido com a opção desligada.
            'SELECT CASE WHEN primario_sem_prazo THEN 1 ELSE 0 END FROM escritorio_configuracao_edicao WHERE tenant_id = :tenant',
            ['tenant' => $tenant->getId()],
        );

        return $valor === false || (int) $valor === 1;
    }

    public function salvar(ConfiguracaoDeEdicao $configuracao): void
    {
        $this->getEntityManager()->persist($configuracao);
        $this->getEntityManager()->flush();
    }
}
