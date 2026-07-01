<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Processo\Entity\Processo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Vincula um processo a uma pasta.
 *
 * Quem: usuário com permissão de edição na pasta. O quê: reunir vários processos sob a
 * mesma pasta. Idempotente: vincular um processo já vinculado é no-op. O primeiro processo
 * vinculado vira automaticamente o principal (representante da pasta nos resumos).
 *
 * Segurança: o processo precisa ser do mesmo escritório (tenant) da pasta — o controller já
 * resolve o processo com lookup tenant-scoped, e aqui reforçamos (defesa em profundidade).
 */
final class VincularProcessoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(Pasta $pasta, Processo $processo, User $usuario): void
    {
        $this->garantirMesmoTenant($pasta, $processo);

        $pasta->vincularProcesso($processo, $usuario);
        $this->em->flush();
    }

    private function garantirMesmoTenant(Pasta $pasta, Processo $processo): void
    {
        $tenantPasta    = $pasta->getTenant();
        $tenantProcesso = $processo->getTenant();

        $mesmoTenant = $tenantPasta !== null
            && $tenantProcesso !== null
            && ($tenantPasta === $tenantProcesso
                || ($tenantPasta->getId() !== null && $tenantPasta->getId() === $tenantProcesso->getId()));

        if (!$mesmoTenant) {
            throw new AccessDeniedException('O processo não pertence ao mesmo escritório da pasta.');
        }
    }
}
