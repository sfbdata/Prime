<?php

declare(strict_types=1);

namespace App\Conector\Repository;

use App\Conector\Entity\EscritorioDoConector;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Ligação e aceite do conector por escritório: uma linha por escritório, sempre buscada pelo escritório.
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
     * Todos os escritórios ativos (nem suspensos nem excluídos) com o conector de cada um, para a tabela do Master. SQL
     * direto (o `TenantFilter` não alcança o DBAL): visão de plataforma de propósito, no molde de
     * {@see \App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository::listarEscritoriosParaOMaster}. Escritório
     * sem linha do conector sai com ligado falso e sem aceite. Sai só o nome do escritório, nunca dado de
     * pessoa.
     *
     * @return list<array{tenantId: int, nome: string, ligado: bool, aceiteEm: ?string, aceiteVersao: ?int}>
     */
    public function listarEscritoriosParaOMaster(): array
    {
        $linhas = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT t.id AS tenant_id, t.name AS nome, e.ligado,
                    e.aceite_em, e.aceite_versao
               FROM tenant t
          LEFT JOIN conector_ia_escritorio e ON e.tenant_id = t.id
              WHERE t.is_active = TRUE AND t.excluido_em IS NULL
           ORDER BY LOWER(t.name), t.id',
        );

        return array_map(static fn (array $l): array => [
            'tenantId' => (int) $l['tenant_id'],
            'nome' => (string) $l['nome'],
            'ligado' => (bool) $l['ligado'],
            'aceiteEm' => $l['aceite_em'] === null ? null : (string) $l['aceite_em'],
            'aceiteVersao' => $l['aceite_versao'] === null ? null : (int) $l['aceite_versao'],
        ], $linhas);
    }
}
