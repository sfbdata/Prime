<?php

namespace App\Profile\UseCase;

use App\Entity\Auth\UserProfile;
use App\Profile\DTO\DadosPessoaisDTO;
use Doctrine\ORM\EntityManagerInterface;

final class AtualizarDadosPessoaisUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(UserProfile $perfil, DadosPessoaisDTO $dto): void
    {
        $perfil->setNomeCompleto($dto->nomeCompleto);
        $perfil->setCpf($dto->cpf !== '' ? $dto->cpf : null);
        $perfil->setDataNascimento($dto->dataNascimento);
        $perfil->setCtps($dto->ctps !== '' ? $dto->ctps : null);
        $perfil->setSerie($dto->serie !== '' ? $dto->serie : null);
        $this->em->flush();
    }
}
