<?php

declare(strict_types=1);

namespace App\Tarefa\Enum;

/**
 * O que a mensagem fez no ciclo da meta (desenho 04B de 22/10, L127): despacho (o "Comentário" do protótipo), envio
 * para revisão ou devolução. Mensagem antiga não tem ação gravada (nulo) e aparece como "Comentário".
 */
enum AcaoDoDespacho: string
{
    case Despacho  = 'despacho';
    case Envio     = 'envio';
    case Devolucao = 'devolucao';

    /** Chave do catálogo (`metas.despacho.tipo.*`), o rótulo da linha do tempo quando não há natureza. */
    public function rotulo(): string
    {
        return 'metas.despacho.tipo.' . $this->value;
    }
}
