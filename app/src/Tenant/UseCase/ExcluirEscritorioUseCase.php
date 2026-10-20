<?php

declare(strict_types=1);

namespace App\Tenant\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tenant\Repository\PedidoDeExclusaoDeContaRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Exclusão (soft delete) de um escritório: desativa (isActive=false) e marca a
 * quarentena (excluidoEm). Não remove dados — a purga definitiva fica para um job
 * futuro. Idempotente: excluir um escritório já excluído é no-op.
 *
 * A autorização (dono/admin do tenant ou SuperAdmin) é responsabilidade do
 * controller chamador — este UseCase só executa o soft delete.
 *
 * PR 5: com pedido de exclusão PENDENTE, a exclusão decide o pedido ("excluida", pelo ator), na mesma
 * transação, como o ExcluirContaPeloMasterUseCase.
 */
final class ExcluirEscritorioUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PedidoDeExclusaoDeContaRepository $pedidos,
    ) {}

    public function executar(Tenant $tenant, User $ator): void
    {
        if ($tenant->isActive() !== true) {
            return;
        }

        $tenant->setIsActive(false);
        $tenant->setExcluidoEm(new \DateTimeImmutable());
        $this->pedidos->pendenteDoEscritorio($tenant)?->marcarExcluida($ator);
        $this->em->flush();
    }
}
