<?php

declare(strict_types=1);

namespace App\Inteligencia\Repository;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConfiguracaoDeInteligencia>
 */
// Não-final: espelha PublicacaoDjenRepository e permite substituição por mock nos testes de UseCase.
class ConfiguracaoDeInteligenciaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConfiguracaoDeInteligencia::class);
    }

    public function salvar(ConfiguracaoDeInteligencia $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** A configuração do escritório, ou null se ele nunca abriu o painel (= desligada). */
    public function findDoTenant(Tenant $tenant): ?ConfiguracaoDeInteligencia
    {
        return $this->findOneBy(['tenant' => $tenant]);
    }

    /**
     * A configuração de um escritório QUALQUER, para o Master (aba "Configurações da I.A."). O tenant vem escrito à
     * mão; o TenantFilter do escritório em que o Master está é suspenso só durante a consulta, senão a linha de outro
     * escritório sumiria. Quem chama já exigiu o Master.
     */
    public function findDoTenantParaOMaster(Tenant $tenant): ?ConfiguracaoDeInteligencia
    {
        $filtros = $this->getEntityManager()->getFilters();
        $estavaLigado = $filtros->isEnabled('tenant');
        if ($estavaLigado) {
            $filtros->suspend('tenant');
        }
        try {
            return $this->findOneBy(['tenant' => $tenant]);
        } finally {
            if ($estavaLigado) {
                $filtros->restore('tenant');
            }
        }
    }

    /**
     * Todos os escritórios ativos (nem suspensos nem excluídos) com a IA de cada um, para a tabela do Master. SQL
     * direto (o TenantFilter não alcança o DBAL): visão de plataforma de propósito. Sai só o nome do escritório,
     * nunca CPF ou e-mail de ninguém.
     *
     * @return list<array{tenant_id: int, nome: string, liberada: bool, habilitada: bool, aceite_em: ?string, modo: ?string, orcamento: ?int}>
     */
    public function listarEscritoriosParaOMaster(): array
    {
        $linhas = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT t.id AS tenant_id, t.name AS nome, c.liberada_pelo_master AS liberada, c.habilitada,
                    c.consentimento_envio_externo_em AS aceite_em, c.modo_de_cobranca AS modo,
                    c.orcamento_mensal_centavos AS orcamento
               FROM tenant t
          LEFT JOIN inteligencia_configuracao c ON c.tenant_id = t.id
              WHERE t.is_active = TRUE AND t.excluido_em IS NULL
           ORDER BY LOWER(t.name), t.id',
        );

        return array_map(static fn (array $l): array => [
            'tenant_id' => (int) $l['tenant_id'],
            'nome' => (string) $l['nome'],
            'liberada' => (bool) $l['liberada'],
            'habilitada' => (bool) $l['habilitada'],
            'aceite_em' => $l['aceite_em'] === null ? null : (string) $l['aceite_em'],
            'modo' => $l['modo'] === null ? null : (string) $l['modo'],
            'orcamento' => $l['orcamento'] === null ? null : (int) $l['orcamento'],
        ], $linhas);
    }

    /**
     * Escritórios com a IA ligada — para o comando de status, que roda sem TenantFilter e lista
     * TODOS os tenants de propósito (é o runbook do operador da plataforma).
     *
     * @return ConfiguracaoDeInteligencia[]
     */
    public function listarHabilitadas(): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.habilitada = true')
            ->orderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
