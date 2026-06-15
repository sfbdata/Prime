<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Tarefa\TarefaMensagem;
use Doctrine\ORM\EntityManagerInterface;

final class EditarTarefaMensagemUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(TarefaMensagem $mensagem, string $conteudo): void
    {
        $conteudo = trim($conteudo);

        if ($conteudo === '' || mb_strlen($conteudo) > 5000) {
            throw new \InvalidArgumentException('Conteúdo inválido: deve ter entre 1 e 5000 caracteres.');
        }

        $mensagem->setMensagem($conteudo);
        $mensagem->setEditadoEm(new \DateTimeImmutable());
        $this->em->flush();
    }
}
