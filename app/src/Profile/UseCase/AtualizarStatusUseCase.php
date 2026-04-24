<?php
declare(strict_types=1);
namespace App\Profile\UseCase;

use App\Profile\DTO\AtualizarStatusInput;
use App\Profile\Entity\UserProfile;
use App\Profile\Repository\UserProfileRepository;

final class AtualizarStatusUseCase
{
    public function __construct(
        private readonly UserProfileRepository $repository,
    ) {
    }

    public function executar(UserProfile $perfil, AtualizarStatusInput $input): void
    {
        $status = $input->status;

        if ($status === null || $status === '') {
            $status = null;
        } else {
            $status = mb_substr($status, 0, 255);
        }

        $perfil->setStatus($status);
        $this->repository->salvar($perfil, flush: true);
    }
}
