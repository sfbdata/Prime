<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\Entity\Pasta;
use App\Processo\Entity\Processo;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Desvincula um processo específico de uma pasta.
 *
 * Quem: usuário com permissão de edição na pasta. O quê: remover um processo da pasta — o
 * registro do Processo permanece (pode estar vinculado a outras pastas). Idempotente: se o
 * processo não estava vinculado, é no-op. Se o desvinculado era o principal e ainda restam
 * processos, o primeiro remanescente é promovido a principal.
 */
final class DesvincularProcessoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(Pasta $pasta, Processo $processo): void
    {
        $pasta->desvincularProcesso($processo);
        $this->em->flush();
    }
}
