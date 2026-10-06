<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reabrir uma meta concluída ("Reabrir meta" no ⋮ da lista da pasta, desenho 1.2.3).
 *
 * É o inverso de `tarefa_concluir`: volta a `pendente` e limpa a data de conclusão —
 * sem ela a linha deixa de dizer "concluída no prazo" e o prazo volta a contar
 * (inclusive como atrasada, se já passou). Mesma guarda de concluir, aplicada pelo
 * controller; a auditoria registra quem reabriu (Tarefa é Auditavel).
 */
final class ReabrirMetaUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return bool true quando reabriu; false quando a meta não estava concluída
     */
    public function executar(Tarefa $tarefa, Tenant $tenant): bool
    {
        if (!self::mesmoEscritorio($tarefa->getTenant(), $tenant)) {
            // O TenantFilter já esconde meta de outro escritório; esta é a segunda trava.
            throw new \LogicException('Meta de outro escritório.');
        }

        if ($tarefa->getStatus() !== Tarefa::STATUS_CONCLUIDA) {
            return false;
        }

        $tarefa->setStatus(Tarefa::STATUS_PENDENTE);
        $tarefa->setDataConclusao(null);
        $this->em->flush();

        return true;
    }

    private static function mesmoEscritorio(?Tenant $daMeta, Tenant $atual): bool
    {
        return $daMeta === $atual
            || ($daMeta !== null && $daMeta->getId() !== null && $daMeta->getId() === $atual->getId());
    }
}
