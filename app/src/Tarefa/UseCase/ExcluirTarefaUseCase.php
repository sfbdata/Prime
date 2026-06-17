<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Tarefa\Exception\MetaNaoExcluivelException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Exclusão controlada de uma Meta (Tarefa).
 *
 * Salvaguardas: só o criador da meta pode excluir, e apenas dentro de uma janela
 * curta após a criação. O registro da exclusão no Histórico da pasta (com autor e
 * data/hora) é automático via auditoria (Tarefa é Auditavel).
 */
final class ExcluirTarefaUseCase
{
    /** Janela em que a meta ainda pode ser excluída, contada da criação. */
    private const JANELA_EXCLUSAO = 'PT24H';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function podeExcluir(Tarefa $tarefa, User $usuario, Tenant $tenant, ?\DateTimeImmutable $agora = null): bool
    {
        $agora ??= new \DateTimeImmutable();

        if ($tarefa->getPasta()->getTenant() !== $tenant) {
            return false;
        }

        $criador = $tarefa->getCriadoPor();
        if ($criador === null) {
            return false;
        }

        $mesmaPessoa = $criador === $usuario
            || ($criador->getId() !== null && $criador->getId() === $usuario->getId());
        if (!$mesmaPessoa) {
            return false;
        }

        $limite = $tarefa->getDataCriacao()->add(new \DateInterval(self::JANELA_EXCLUSAO));

        return $agora <= $limite;
    }

    public function executar(Tarefa $tarefa, User $usuario, Tenant $tenant): void
    {
        if (!$this->podeExcluir($tarefa, $usuario, $tenant)) {
            throw new MetaNaoExcluivelException('Esta meta não pode ser excluída.');
        }

        $this->em->remove($tarefa);
        $this->em->flush();
    }
}
