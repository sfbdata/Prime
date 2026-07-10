<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\Entity\Pasta;
use App\Pasta\DTO\EditarPastaDTO;
use Doctrine\ORM\EntityManagerInterface;

final class EditarPastaUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(EditarPastaDTO $dto, Pasta $pasta): void
    {
        $nup = trim($dto->nup);

        if ($nup === '') {
            throw new \InvalidArgumentException('O NUP é obrigatório.');
        }

        // NUP pode repetir (sync Drive): a identidade é o driveFolderId, não o NUP.
        $pasta->setNup($nup);
        $pasta->setNomeCliente($dto->nomeCliente);
        $pasta->setNomeAcao($dto->nomeAcao);

        if (in_array($dto->situacao, [Pasta::SITUACAO_ATIVA, Pasta::SITUACAO_ARQUIVADA], true)) {
            $pasta->setSituacao($dto->situacao);
        }

        $this->em->flush();
    }
}
