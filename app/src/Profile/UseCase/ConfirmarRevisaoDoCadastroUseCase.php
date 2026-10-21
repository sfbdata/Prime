<?php

declare(strict_types=1);

namespace App\Profile\UseCase;

use App\Profile\Entity\UserProfile;
use App\Profile\Repository\UserProfileRepository;

/**
 * "Está tudo certo" da revisão mensal do cadastro (desenho "07 - Meu Perfil", VS 18.10.26, revOk):
 * grava a data da confirmação. Quem: a própria pessoa.
 */
final class ConfirmarRevisaoDoCadastroUseCase
{
    public function __construct(
        private readonly UserProfileRepository $repository,
    ) {
    }

    public function executar(UserProfile $perfil, ?\DateTimeImmutable $hoje = null): void
    {
        $perfil->confirmarRevisao(($hoje ?? new \DateTimeImmutable('today'))->setTime(0, 0));
        $this->repository->salvar($perfil, flush: true);
    }
}
