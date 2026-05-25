<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaSecao;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaSecaoRepository;
use Doctrine\ORM\EntityManagerInterface;

final class CriarPastaSecaoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PastaSecaoRepository $secaoRepository,
    ) {
    }

    public function executar(Pasta $pasta, User $autor, string $nome, Tenant $tenant): PastaSecao
    {
        $nome = trim($nome);

        if ($nome === '') {
            throw new \InvalidArgumentException('O nome da seção não pode ser vazio.');
        }

        if (mb_strlen($nome) > 255) {
            throw new \InvalidArgumentException('O nome da seção deve ter no máximo 255 caracteres.');
        }

        $ordem = $this->secaoRepository->proximaOrdem($pasta, $tenant);

        $secao = new PastaSecao();
        $secao->setPasta($pasta);
        $secao->setTenant($tenant);
        $secao->setNome($nome);
        $secao->setOrdem($ordem);

        $this->em->persist($secao);
        $this->em->flush();

        return $secao;
    }
}
