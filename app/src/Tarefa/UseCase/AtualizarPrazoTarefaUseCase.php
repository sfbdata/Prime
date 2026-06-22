<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Tarefa\Exception\PrazoNaoEditavelException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Atualização do prazo de uma Meta (Tarefa).
 *
 * Salvaguarda: só o criador da meta pode alterar o prazo. Sem janela de tempo e
 * sem restrição de status — pode alterar a qualquer momento, inclusive limpar o
 * prazo (null). O registro da alteração no Histórico da pasta (com autor e
 * data/hora) é automático via auditoria (Tarefa é Auditavel).
 */
final class AtualizarPrazoTarefaUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function podeEditarPrazo(Tarefa $tarefa, User $usuario, Tenant $tenant): bool
    {
        if ($tarefa->getPasta()->getTenant() !== $tenant) {
            return false;
        }

        $criador = $tarefa->getCriadoPor();
        if ($criador === null) {
            return false;
        }

        return $criador === $usuario
            || ($criador->getId() !== null && $criador->getId() === $usuario->getId());
    }

    public function executar(Tarefa $tarefa, User $usuario, Tenant $tenant, ?\DateTimeImmutable $prazo): void
    {
        if (!$this->podeEditarPrazo($tarefa, $usuario, $tenant)) {
            throw new PrazoNaoEditavelException('O prazo desta meta não pode ser alterado.');
        }

        $tarefa->setPrazo($prazo);
        $this->em->flush();
    }
}
