<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Tarefa\Enum\AcaoDoDespacho;
use App\Tarefa\Exception\MetaJaSeguiuAdianteException;
use App\Tarefa\Service\TransacaoDaPassagem;

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
        private readonly TransacaoDaPassagem $transacao,
    ) {
    }

    /**
     * @return bool true quando reabriu; false quando a meta não estava concluída
     *
     * @throws MetaJaSeguiuAdianteException a passagem de etapa já a levou adiante
     */
    public function executar(Tarefa $tarefa, Tenant $tenant): bool
    {
        if (!self::mesmoEscritorio($tarefa->getTenant(), $tenant)) {
            // O TenantFilter já esconde meta de outro escritório; esta é a segunda trava.
            throw new \LogicException('Meta de outro escritório.');
        }

        // Travada e relida do banco: a decisão vale pelo status gravado, não pelo que esta tela tinha em memória.
        return $this->transacao->emTransacao(function () use ($tarefa): bool {
            $this->transacao->travarERecarregar($tarefa);
            if ($tarefa->getStatus() !== Tarefa::STATUS_CONCLUIDA) {
                return false;
            }
            if (self::seguiuAdiante($tarefa)) {
                throw new MetaJaSeguiuAdianteException();
            }
            $tarefa->setStatus(Tarefa::STATUS_PENDENTE);
            $tarefa->setDataConclusao(null);

            return true;
        });
    }

    /**
     * A meta que a passagem de etapa concluiu: ela grava o ato "Etapa concluída" ({@see AcaoDoDespacho::Etapa}) no
     * histórico da própria meta. A tela usa a mesma regra para esconder o Reabrir.
     */
    public static function seguiuAdiante(Tarefa $tarefa): bool
    {
        return $tarefa->getStatus() === Tarefa::STATUS_CONCLUIDA
            && $tarefa->getMensagens()->exists(static fn (int|string $i, TarefaMensagem $ato): bool => $ato->getAcao() === AcaoDoDespacho::Etapa);
    }

    private static function mesmoEscritorio(?Tenant $daMeta, Tenant $atual): bool
    {
        return $daMeta === $atual
            || ($daMeta !== null && $daMeta->getId() !== null && $daMeta->getId() === $atual->getId());
    }
}
