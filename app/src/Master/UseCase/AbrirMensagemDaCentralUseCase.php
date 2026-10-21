<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Entity\Auth\User;
use App\Master\DTO\ItemDaCentral;
use App\Master\DTO\MensagemAbertaOutput;
use App\Master\DTO\RespostaDaCentralOutput;
use App\Master\Entity\CentralMasterResposta;
use App\Master\Notificacoes\OrigensDaCentral;
use App\Master\Repository\CentralMasterEstadoRepository;
use App\Master\Repository\CentralMasterRespostaRepository;
use Psr\Clock\ClockInterface;

/**
 * O Master abre uma mensagem da Central: ela fica lida para ELE e volta com o histórico de respostas de todos os
 * Masters (desenho: "Você respondeu"), mais a resposta dada antes pela página do Contribua, se ela ainda não foi
 * guardada na Central. Não mexe nas Notificacao do sino (D8). Nulo se a mensagem não existe mais.
 */
final class AbrirMensagemDaCentralUseCase
{
    public function __construct(
        private readonly OrigensDaCentral $origens,
        private readonly CentralMasterEstadoRepository $estados,
        private readonly CentralMasterRespostaRepository $respostas,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(User $master, string $origem, int $idOrigem): ?MensagemAbertaOutput
    {
        $mensagem = $this->origens->mensagem($origem, $idOrigem);
        if ($mensagem === null) {
            return null;
        }
        $estado = $this->estados->daMensagemOuNova($master, $mensagem);
        $estado->marcarLida($this->relogio->now());
        $this->estados->salvar($estado, flush: true);

        $historico = $this->historico($master, $origem, $idOrigem);

        return new MensagemAbertaOutput(
            ItemDaCentral::de($mensagem, $estado, $historico !== [] || $mensagem->respondidaNaOrigem),
            $historico,
        );
    }

    /**
     * As respostas da Central e, se ainda não estiver nelas, a que a própria origem guarda (a dada pela página do
     * Contribua), em ordem de data.
     *
     * @return list<RespostaDaCentralOutput>
     */
    private function historico(User $master, string $origem, int $idOrigem): array
    {
        $daCentral = $this->respostas->daMensagem($origem, $idOrigem);
        $historico = array_map(static fn (CentralMasterResposta $r): RespostaDaCentralOutput => RespostaDaCentralOutput::daResposta($r, $master), $daCentral);
        $naOrigem = $this->origens->da($origem)?->respostaNaOrigem($idOrigem);
        $textos = array_map(static fn (CentralMasterResposta $r): string => $r->getTexto(), $daCentral);
        if ($naOrigem !== null && !in_array($naOrigem->texto, $textos, true)) {
            $historico[] = RespostaDaCentralOutput::daOrigem($naOrigem, $master);
            usort($historico, static fn (RespostaDaCentralOutput $a, RespostaDaCentralOutput $b): int => $a->em <=> $b->em);
        }

        return $historico;
    }
}
