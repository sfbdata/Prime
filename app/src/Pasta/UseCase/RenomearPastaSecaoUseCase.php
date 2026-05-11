<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Pasta\PastaSecao;
use App\Entity\Tenant\Tenant;
use App\Repository\Pasta\PastaSecaoRepository;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class RenomearPastaSecaoUseCase
{
    public function __construct(
        private readonly PastaSecaoRepository $secaoRepository,
    ) {
    }

    public function executar(PastaSecao $secao, User $autor, string $novoNome, Tenant $tenant): void
    {
        if ($secao->getTenant() !== $tenant) {
            throw new AccessDeniedException('Seção não pertence ao tenant do usuário.');
        }

        $novoNome = trim($novoNome);

        if ($novoNome === '') {
            throw new \InvalidArgumentException('O nome da seção não pode ser vazio.');
        }

        if (mb_strlen($novoNome) > 255) {
            throw new \InvalidArgumentException('O nome da seção deve ter no máximo 255 caracteres.');
        }

        $secao->setNome($novoNome);

        $this->secaoRepository->salvar($secao, flush: true);
    }
}
