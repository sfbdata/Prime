<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Pasta\Pasta;
use App\Entity\Pasta\PastaObservacaoFinanceira;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;

final class EnviarObservacaoFinanceiraUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(Pasta $pasta, User $autor, string $conteudo, Tenant $tenant): PastaObservacaoFinanceira
    {
        $conteudo = trim($conteudo);

        if ($conteudo === '' || mb_strlen($conteudo) > 5000) {
            throw new \InvalidArgumentException('Conteúdo inválido: deve ter entre 1 e 5000 caracteres.');
        }

        $obs = new PastaObservacaoFinanceira();
        $obs->setPasta($pasta);
        $obs->setAutor($autor);
        $obs->setTenant($tenant);
        $obs->setConteudo($conteudo);

        $this->em->persist($obs);
        $this->em->flush();

        return $obs;
    }
}
