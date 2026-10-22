<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Pasta\DTO\TimelineItemDTO;
use App\Pasta\DTO\TimelineItemType;
use App\Tarefa\DTO\DespachoNaLinhaDoTempoOutput;
use App\Tarefa\DTO\DespachosDaMetaOutput;
use App\Tarefa\DTO\ItemDaLinhaDoTempoOutput;

/**
 * Linha do tempo da meta no formato único do desenho 04B de 22/10 (L70-71, L127; spec L6 e os ajustes de
 * fidelidade): despachos e eventos da auditoria no mesmo cartão, "DESPACHO N" do mais antigo (1) ao mais novo, e o
 * mais novo com "MAIS RECENTE".
 *
 * Tipos e cores (L127): Comentário (azul), Enviado para revisão e Devolvida (âmbar), Prazo alterado e Responsáveis
 * alterados (roxo), concluída (verde). Os eventos que o desenho não tem (meta criada, atualizada, reaberta, removida)
 * seguem no mesmo formato, em cinza, para não perder informação.
 *
 * A troca de status gravada pela auditoria junto de um despacho de envio ou devolução (até
 * {@see JANELA_DO_MESMO_ATO} segundos) é o mesmo ato e sai da linha: o despacho já mostra o tipo.
 */
final class MontarLinhaDoTempoDaMetaUseCase
{
    public const JANELA_DO_MESMO_ATO = 120;

    /** Evento da auditoria (`TimelineItemDTO::$sistema`) => [chave do tipo, ícone, tom]. */
    private const EVENTOS = [
        'revisao'      => ['metas.despacho.tipo.envio', 'bi-send', 'ambar'],
        'devolvida'    => ['metas.despacho.tipo.devolucao', 'bi-arrow-return-left', 'ambar'],
        'prazo'        => ['metas.linha.tipo.prazo', 'bi-calendar-event', 'roxo'],
        'responsaveis' => ['metas.linha.tipo.responsaveis', 'bi-people', 'roxo'],
        'concluida'    => ['metas.linha.tipo.concluida', 'bi-check2-circle', 'verde'],
        'criada'       => ['metas.linha.tipo.criada', 'bi-list-task', 'cinza'],
        'reaberta'     => ['metas.linha.tipo.reaberta', 'bi-arrow-repeat', 'cinza'],
        'removida'     => ['metas.linha.tipo.removida', 'bi-trash', 'cinza'],
        'atualizada'   => ['metas.linha.tipo.atualizada', 'bi-pencil-square', 'cinza'],
    ];

    /** Ação do despacho => [ícone, tom]. */
    private const DESPACHOS = [
        'envio'     => ['bi-send', 'ambar'],
        'devolucao' => ['bi-arrow-return-left', 'ambar'],
    ];

    /**
     * @param TimelineItemDTO[] $itens do mais novo para o mais antigo, como o `TarefaTimelineAssembler` devolve
     *
     * @return list<ItemDaLinhaDoTempoOutput> na mesma ordem
     */
    public function executar(array $itens, DespachosDaMetaOutput $despachos): array
    {
        $itens = array_values(array_filter($itens, fn (TimelineItemDTO $i): bool => !$this->ehOMesmoAtoDeUmDespacho($i, $despachos)));
        $total = count($itens);

        $saida = [];
        foreach ($itens as $indice => $item) {
            $despacho = $item->tipo === TimelineItemType::MENSAGEM ? ($despachos->linhas[(int) $item->mensagemId] ?? null) : null;
            [$tipo, $icone, $tom] = $despacho !== null ? self::visualDoDespacho($despacho) : (self::EVENTOS[(string) $item->sistema] ?? ['metas.linha.tipo.evento', 'bi-clock-history', 'cinza']);
            $saida[] = new ItemDaLinhaDoTempoOutput($total - $indice, $indice === 0, $item, $despacho, $tipo, $icone, $tom);
        }

        return $saida;
    }

    /** @return array{string, string, string} */
    private static function visualDoDespacho(DespachoNaLinhaDoTempoOutput $d): array
    {
        [$icone, $tom] = self::DESPACHOS[(string) $d->acao] ?? ['bi-chat-left-text', 'azul'];

        return [$d->tipo, $icone, $tom];
    }

    private function ehOMesmoAtoDeUmDespacho(TimelineItemDTO $item, DespachosDaMetaOutput $despachos): bool
    {
        $acao = ['revisao' => 'envio', 'devolvida' => 'devolucao'][(string) $item->sistema] ?? null;
        if ($item->tipo !== TimelineItemType::EVENTO || $acao === null) {
            return false;
        }
        foreach ($despachos->linhas as $d) {
            if ($d->acao === $acao && abs($d->registradoEm->getTimestamp() - $item->dataHora->getTimestamp()) <= self::JANELA_DO_MESMO_ATO) {
                return true;
            }
        }

        return false;
    }
}
