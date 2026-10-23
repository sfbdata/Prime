<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Pasta\Entity\PastaChecklistItem;
use App\Pasta\Service\AuditoriaDoChecklist;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Marca ou desmarca um item do checklist. Marcado sem o documento anexado, quem marcou e a controladoria
 * recebem o aviso da auditoria "sem anexo" (desenho 02, `ckAuditar` ao marcar); desmarcar limpa.
 */
final class ToggleChecklistItemUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ?AuditoriaDoChecklist $auditoria = null,
    ) {
    }

    public function executar(PastaChecklistItem $item, ?User $quem = null): void
    {
        $item->toggle();
        if ($quem !== null) {
            $this->auditoria?->aoAlternar($item, $quem, new \DateTimeImmutable());
        }
        $this->em->flush();
    }
}
