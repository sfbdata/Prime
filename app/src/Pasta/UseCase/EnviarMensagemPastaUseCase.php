<?php

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Pasta\Pasta;
use App\Entity\Pasta\PastaMensagem;
use Doctrine\ORM\EntityManagerInterface;

class EnviarMensagemPastaUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(Pasta $pasta, User $autor, string $conteudo): PastaMensagem
    {
        $conteudo = trim($conteudo);

        if ($conteudo === '' || mb_strlen($conteudo) > 5000) {
            throw new \InvalidArgumentException('Conteúdo inválido: deve ter entre 1 e 5000 caracteres.');
        }

        $tenant = $autor->getTenant();
        if ($tenant === null) {
            throw new \LogicException('Usuário sem tenant.');
        }

        $mensagem = new PastaMensagem();
        $mensagem->setPasta($pasta);
        $mensagem->setAutor($autor);
        $mensagem->setTenant($tenant);
        $mensagem->setConteudo($conteudo);

        $this->em->persist($mensagem);
        $this->em->flush();

        return $mensagem;
    }
}
