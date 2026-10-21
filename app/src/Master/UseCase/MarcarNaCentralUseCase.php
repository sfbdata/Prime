<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Entity\Auth\User;
use App\Master\DTO\MarcarNaCentralInput;
use App\Master\Entity\CentralMasterEstado;
use App\Master\Notificacoes\OrigensDaCentral;
use App\Master\Repository\CentralMasterEstadoRepository;
use Psr\Clock\ClockInterface;

/**
 * Marca em lote as mensagens da seleção na Central do Master (desenho: Marcar como lida, Não lida, Arquivar,
 * Desarquivar e a estrela). O estado é só DESTE Master (D1): o outro Master não vê a mudança.
 *
 * Só marca mensagem que existe na origem: chave desconhecida ou de mensagem que sumiu é ignorada, sem criar linha
 * órfã. Devolve quantas foram marcadas.
 */
final class MarcarNaCentralUseCase
{
    public function __construct(
        private readonly OrigensDaCentral $origens,
        private readonly CentralMasterEstadoRepository $estados,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(User $master, MarcarNaCentralInput $input): int
    {
        $marcados = [];
        foreach ($input->mensagens as [$origem, $idOrigem]) {
            $mensagem = $this->origens->mensagem($origem, $idOrigem);
            if ($mensagem === null) {
                continue;
            }
            $estado = $this->estados->daMensagemOuNova($master, $mensagem);
            $this->aplicar($estado, $input->acao);
            $marcados[] = $estado;
        }
        foreach ($marcados as $i => $estado) {
            $this->estados->salvar($estado, flush: $i === array_key_last($marcados));
        }

        return count($marcados);
    }

    private function aplicar(CentralMasterEstado $estado, string $acao): void
    {
        $agora = $this->relogio->now();
        match ($acao) {
            MarcarNaCentralInput::LIDA => $estado->marcarLida($agora),
            MarcarNaCentralInput::NAO_LIDA => $estado->marcarNaoLida(),
            MarcarNaCentralInput::ESTRELA => $estado->definirEstrela(true),
            MarcarNaCentralInput::SEM_ESTRELA => $estado->definirEstrela(false),
            MarcarNaCentralInput::ARQUIVAR => $estado->arquivar($agora),
            MarcarNaCentralInput::DESARQUIVAR => $estado->desarquivar(),
            default => throw new \InvalidArgumentException('Ação desconhecida.'),
        };
    }
}
