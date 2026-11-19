<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\ChecklistModeloOutput;
use App\Pasta\Repository\PastaChecklistModeloRepository;

/**
 * Os modelos de checklist do escritório, na ordem do painel de modelos (nome e itens de cada um).
 *
 * Só leitura. O modelo é do ESCRITÓRIO: a permissão de editar a pasta aberta é conferida por quem chama.
 */
final class ListarModelosDeChecklistUseCase
{
    public function __construct(
        private readonly PastaChecklistModeloRepository $modeloRepository,
    ) {
    }

    /** @return list<ChecklistModeloOutput> */
    public function executar(Tenant $tenant): array
    {
        return array_map(
            static fn ($modelo): ChecklistModeloOutput => ChecklistModeloOutput::fromEntity($modelo),
            $this->modeloRepository->listarDoTenant($tenant),
        );
    }
}
