<?php

declare(strict_types=1);

namespace App\Sync\UseCase;

use App\Entity\Tenant\Tenant;
use App\Sync\DTO\ReconhecimentoPendenteOutput;
use App\Sync\Repository\ReconhecimentoPendenteRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * As dúvidas pendentes do reconhecimento do Drive do escritório, com o número e o cliente de cada pasta candidata.
 * Candidata que saiu do escritório, foi para a lixeira ou ganhou vínculo depois não aparece mais.
 */
final class ListarReconhecimentosPendentesUseCase
{
    public function __construct(
        private readonly ReconhecimentoPendenteRepository $pendencias,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return list<ReconhecimentoPendenteOutput> */
    public function executar(Tenant $tenant): array
    {
        $pendencias = $this->pendencias->listarPendentesDoTenant($tenant);
        $ids = [];
        foreach ($pendencias as $p) {
            $ids = array_merge($ids, $p->getCandidatos());
        }
        $pastas = $this->pastasLivres((int) $tenant->getId(), array_values(array_unique($ids)));

        $saida = [];
        foreach ($pendencias as $p) {
            $candidatas = array_values(array_filter(array_map(
                static fn (int $id): ?array => $pastas[$id] ?? null,
                $p->getCandidatos(),
            )));
            $saida[] = new ReconhecimentoPendenteOutput((int) $p->getId(), $p->getNomeNoDrive(), $candidatas);
        }

        return $saida;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, array{id: int, nup: string, cliente: string}>
     */
    private function pastasLivres(int $tenantId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT id, nup, nome_cliente FROM pasta WHERE tenant_id = :t AND id IN (:ids) AND drive_folder_id IS NULL AND excluida_em IS NULL',
            ['t' => $tenantId, 'ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );
        $pastas = [];
        foreach ($rows as $r) {
            $pastas[(int) $r['id']] = ['id' => (int) $r['id'], 'nup' => (string) $r['nup'], 'cliente' => (string) $r['nome_cliente']];
        }

        return $pastas;
    }
}
