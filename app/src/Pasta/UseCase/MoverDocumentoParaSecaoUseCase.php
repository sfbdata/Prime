<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class MoverDocumentoParaSecaoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(PastaDocumento $documento, ?PastaSecao $secaoDestino, User $autor, Tenant $tenant): void
    {
        $pastaDodocumento = $documento->getPasta();
        if ($pastaDodocumento === null) {
            throw new \LogicException('Documento sem pasta associada.');
        }

        if ($secaoDestino !== null && $secaoDestino->getTenant() !== $tenant) {
            throw new AccessDeniedException('Seção de destino não pertence ao tenant do usuário.');
        }

        if ($secaoDestino !== null && $secaoDestino->getPasta() !== $pastaDodocumento) {
            throw new \InvalidArgumentException('A seção de destino não pertence à mesma pasta do documento.');
        }

        $documento->setSecao($secaoDestino);

        $this->em->flush();
    }
}
