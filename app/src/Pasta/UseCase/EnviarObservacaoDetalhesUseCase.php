<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Pasta\Pasta;
use App\Entity\Pasta\PastaObservacaoDetalhes;
use Doctrine\ORM\EntityManagerInterface;

final class EnviarObservacaoDetalhesUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(Pasta $pasta, User $autor, string $conteudo): PastaObservacaoDetalhes
    {
        $conteudo = trim($conteudo);

        if ($conteudo === '' || mb_strlen($conteudo) > 5000) {
            throw new \InvalidArgumentException('Conteúdo inválido: deve ter entre 1 e 5000 caracteres.');
        }

        $tenant = $autor->getTenant();
        if ($tenant === null) {
            throw new \LogicException('Usuário sem tenant.');
        }

        $obs = new PastaObservacaoDetalhes();
        $obs->setPasta($pasta);
        $obs->setAutor($autor);
        $obs->setTenant($tenant);
        $obs->setConteudo($conteudo);

        $this->em->persist($obs);
        $this->em->flush();

        return $obs;
    }
}
