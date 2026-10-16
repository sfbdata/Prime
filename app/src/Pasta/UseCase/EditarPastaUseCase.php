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

        // As 4 situações do desenho: aceitar só Ativo/Arquivado fazia o modal (que não tinha Suspenso nem Cancelado)
        // mandar "ativo" e reativar a pasta suspensa sem ninguém pedir.
        if (in_array($dto->situacao, Pasta::SITUACOES, true)) {
            $pasta->setSituacao($dto->situacao);
        }

        $this->em->flush();
    }
}
