<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Tarefa\Tarefa;
use App\Tarefa\Service\TransacaoDaPassagem;

/**
 * Conclui a meta pela aba Metas da pasta (`tarefa_concluir`). Com a meta travada e relida do banco dentro da
 * transação: se outra gravação (a passagem de etapa, outra aba) já a concluiu, nada muda e a data de conclusão
 * original fica. Quem pode concluir (D3) o controller confere antes.
 */
final class ConcluirMetaUseCase
{
    public function __construct(
        private readonly TransacaoDaPassagem $transacao,
    ) {
    }

    /** @return bool true quando concluiu; false quando a meta já estava concluída no banco */
    public function executar(Tarefa $meta, \DateTimeImmutable $agora): bool
    {
        return $this->transacao->emTransacao(function () use ($meta, $agora): bool {
            $this->transacao->travarERecarregar($meta);
            if ($meta->getStatus() === Tarefa::STATUS_CONCLUIDA) {
                return false;
            }
            $meta->setStatus(Tarefa::STATUS_CONCLUIDA)->setDataConclusao($agora);

            return true;
        });
    }
}
