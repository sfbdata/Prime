<?php

declare(strict_types=1);

namespace App\Conector\Repository;

use App\Conector\Entity\EscritorioDoConector;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Liberação e aceite do conector por escritório: uma linha por escritório, sempre buscada pelo escritório.
 *
 * @extends ServiceEntityRepository<EscritorioDoConector>
 */
class EscritorioDoConectorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EscritorioDoConector::class);
    }

    public function salvar(EscritorioDoConector $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function buscarDoEscritorio(Tenant $tenant): ?EscritorioDoConector
    {
        return $this->findOneBy(['tenant' => $tenant]);
    }

    /**
     * A linha de um escritório QUALQUER, para o Master liberar ou bloquear (nível 2, 14.a). O escritório vem escrito à
     * mão; o `TenantFilter` do escritório em que o Master está é suspenso só durante a consulta, senão a linha de outro
     * escritório sumiria (molde: {@see \App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository::findDoTenantParaOMaster}).
     * Quem chama já exigiu o Master. Nulo = o escritório nunca teve linha (= não liberado).
     */
    public function buscarDoEscritorioParaOMaster(Tenant $tenant): ?EscritorioDoConector
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
     * Todos os escritórios ativos (nem suspensos nem excluídos) com o conector de cada um, para a tabela do Master. SQL
     * direto (o `TenantFilter` não alcança o DBAL): visão de plataforma de propósito, no molde de
     * {@see \App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository::listarEscritoriosParaOMaster}. Escritório
     * sem linha do conector sai com liberado e ligado falsos e sem aceite. Sai só o nome do escritório, nunca dado de
     * pessoa.
     *
     * @return list<array{tenantId: int, nome: string, liberado: bool, ligado: bool, aceiteEm: ?string, aceiteVersao: ?int}>
     */
    public function listarEscritoriosParaOMaster(): array
    {
        $linhas = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT t.id AS tenant_id, t.name AS nome, e.liberado_pelo_master AS liberado, e.ligado,
                    e.aceite_em, e.aceite_versao
               FROM tenant t
          LEFT JOIN conector_ia_escritorio e ON e.tenant_id = t.id
              WHERE t.is_active = TRUE AND t.excluido_em IS NULL
           ORDER BY LOWER(t.name), t.id',
        );

        return array_map(static fn (array $l): array => [
            'tenantId' => (int) $l['tenant_id'],
            'nome' => (string) $l['nome'],
            'liberado' => (bool) $l['liberado'],
            'ligado' => (bool) $l['ligado'],
            'aceiteEm' => $l['aceite_em'] === null ? null : (string) $l['aceite_em'],
            'aceiteVersao' => $l['aceite_versao'] === null ? null : (int) $l['aceite_versao'],
        ], $linhas);
    }
}
