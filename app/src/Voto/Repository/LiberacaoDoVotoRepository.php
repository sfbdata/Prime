<?php

declare(strict_types=1);

namespace App\Voto\Repository;

use App\Entity\Tenant\Tenant;
use App\Voto\Entity\LiberacaoDoVoto;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LiberacaoDoVoto>
 */
// Não-final: os testes de UseCase do Voto o substituem por mock.
class LiberacaoDoVotoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LiberacaoDoVoto::class);
    }

    public function salvar(LiberacaoDoVoto $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** A liberação do escritório da sessão, ou null se o Master nunca mexeu nela (= não liberado). */
    public function findDoTenant(Tenant $tenant): ?LiberacaoDoVoto
    {
        return $this->findOneBy(['tenant' => $tenant]);
    }

    /**
     * A liberação de um escritório QUALQUER, para o Master (aba "Voto" do painel). O tenant vem escrito à mão; o
     * TenantFilter do escritório em que o Master está é suspenso só durante a consulta, senão a linha de outro
     * escritório sumiria. Quem chama já exigiu o Master.
     */
    public function findDoTenantParaOMaster(Tenant $tenant): ?LiberacaoDoVoto
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
     * Todos os escritórios ativos (nem suspensos nem excluídos) com a liberação de cada um, para a grade do Master. SQL
     * direto (o TenantFilter não alcança o DBAL): visão de plataforma de propósito. Sai só o nome do escritório, nunca
     * CPF ou e-mail de ninguém. Sem linha no schema `voto`, o escritório aparece como não liberado.
     *
     * @return list<array{tenant_id: int, nome: string, liberado: bool}>
     */
    public function listarEscritoriosParaOMaster(): array
    {
        $linhas = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT t.id AS tenant_id, t.name AS nome, COALESCE(l.liberado, FALSE) AS liberado
               FROM tenant t
          LEFT JOIN voto.liberacao l ON l.tenant_id = t.id
              WHERE t.is_active = TRUE AND t.excluido_em IS NULL
           ORDER BY LOWER(t.name), t.id',
        );

        return array_map(static fn (array $l): array => [
            'tenant_id' => (int) $l['tenant_id'],
            'nome' => (string) $l['nome'],
            'liberado' => (bool) $l['liberado'],
        ], $linhas);
    }
}
