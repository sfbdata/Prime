<?php

declare(strict_types=1);

namespace App\Tenant\UseCase;

use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Exclusão (soft delete) de um escritório: desativa (isActive=false) e marca a
 * quarentena (excluidoEm). Não remove dados — a purga definitiva fica para um job
 * futuro. Idempotente: excluir um escritório já excluído é no-op.
 *
 * A autorização (dono/admin do tenant ou SuperAdmin) é responsabilidade do
 * controller chamador — este UseCase só executa o soft delete.
 */
final class ExcluirEscritorioUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(Tenant $tenant): void
    {
        if ($tenant->isActive() !== true) {
            return;
        }

        $tenant->setIsActive(false);
        $tenant->setExcluidoEm(new \DateTimeImmutable());
        $this->em->flush();
    }
}
