<?php
declare(strict_types=1);
namespace App\Profile\UseCase;

use App\Profile\DTO\DadosPessoaisInput;
use App\Profile\Entity\UserProfile;
use App\Profile\Repository\UserProfileRepository;

final class AtualizarDadosPessoaisUseCase
{
    public function __construct(
        private readonly UserProfileRepository $repository,
    ) {
    }

    public function executar(UserProfile $perfil, DadosPessoaisInput $input): void
    {
        $perfil->setNomeCompleto($input->nomeCompleto);
        $perfil->setCpf(($input->cpf !== '' && $input->cpf !== null) ? $input->cpf : null);
        $perfil->setDataNascimento($input->dataNascimento);
        $perfil->setCtps(($input->ctps !== '' && $input->ctps !== null) ? $input->ctps : null);
        $perfil->setSerie(($input->serie !== '' && $input->serie !== null) ? $input->serie : null);

        $this->repository->salvar($perfil, flush: true);
    }
}
