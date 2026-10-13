<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Tarefa\Exception\DesfazerReaberturaRecusadoException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Desfazer" do aviso "Meta reaberta" na aba Metas da pasta (PMC-061).
 *
 * Quem: quem acabou de reabrir a meta, pela mesma rota e guarda do `tarefa_concluir`.
 * O quê: a meta volta a concluída com a data de conclusão ORIGINAL — a que o reabrir apagou —,
 * não com a data de agora. Por quê: desfazer é voltar ao estado anterior; gravar "agora" mudava
 * a história da meta (e podia transformá-la em "concluída fora do prazo").
 *
 * A data anterior vem do servidor (`ReaberturaDesfazivelDaMeta`, na sessão de quem reabriu),
 * nunca do navegador; mesmo assim ela é conferida aqui: não pode ser anterior à criação da meta
 * nem estar no futuro. Sem data válida, recusa — nunca cai para "concluir agora", que é
 * justamente o defeito. A alteração é auditada pelo `AuditLogSubscriber` (Tarefa é Auditavel).
 */
final class DesfazerReaberturaDaMetaUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return bool true quando restaurou; false quando a meta já estava concluída (nada a desfazer)
     *
     * @throws DesfazerReaberturaRecusadoException sem data lembrada ou com data impossível
     */
    public function executar(Tarefa $tarefa, Tenant $tenant, ?\DateTimeImmutable $dataConclusaoAnterior, \DateTimeImmutable $agora): bool
    {
        if (!self::mesmoEscritorio($tarefa->getTenant(), $tenant)) {
            // O TenantFilter e a guarda do controller já barram meta de outro escritório.
            throw new \LogicException('Meta de outro escritório.');
        }

        if ($tarefa->getStatus() === Tarefa::STATUS_CONCLUIDA) {
            return false;
        }

        if ($dataConclusaoAnterior === null) {
            throw new DesfazerReaberturaRecusadoException('Não foi possível desfazer: a meta continua reaberta. Conclua-a pelo menu, se for o caso.');
        }

        if ($dataConclusaoAnterior > $agora || $dataConclusaoAnterior < $tarefa->getDataCriacao()) {
            throw new DesfazerReaberturaRecusadoException('Não foi possível desfazer: a data de conclusão anterior não é válida.');
        }

        $tarefa->setStatus(Tarefa::STATUS_CONCLUIDA);
        $tarefa->setDataConclusao($dataConclusaoAnterior);
        $this->em->flush();

        return true;
    }

    private static function mesmoEscritorio(?Tenant $daMeta, Tenant $atual): bool
    {
        return $daMeta === $atual
            || ($daMeta !== null && $daMeta->getId() !== null && $daMeta->getId() === $atual->getId());
    }
}
