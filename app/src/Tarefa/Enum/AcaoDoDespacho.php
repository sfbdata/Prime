<?php

declare(strict_types=1);

namespace App\Tarefa\Enum;

/**
 * O que a mensagem fez no ciclo da meta (desenho 04B de 22/10, L127): despacho (o "Comentário" do protótipo), envio
 * para revisão ou devolução. Mensagem antiga não tem ação gravada (nulo) e aparece como "Comentário".
 *
 * `Lembrete` é o "Lembrete enviado para X, Y." do botão Lembrar (04B L133): o desenho o grava no histórico como
 * comentário, então o rótulo é o do despacho. Não é ato do ciclo: não leva código de registro (D1), natureza,
 * prazo nem certidão, e não se edita.
 *
 * `Redistribuicao` é o "Responsável alterado de A para B por C." do Redistribuir do painel (spec
 * painel-acompanhamento-redistribuir, fatia B): como o Lembrete, sem código de registro, natureza nem prazo, e não
 * se edita. Cabe no varchar(20) da coluna `acao`: sem migration.
 *
 * `Etapa` é o "Etapa concluída" da passagem de etapa (04B L178): "X concluída → Y (Resp)" e as pendências, com a
 * linha de registro (D1) como o despacho (04B L161).
 */
enum AcaoDoDespacho: string
{
    case Despacho  = 'despacho';
    case Envio     = 'envio';
    case Devolucao = 'devolucao';
    case Lembrete  = 'lembrete';
    case Etapa     = 'etapa';
    case Redistribuicao = 'redistribuicao';

    /** Chave do catálogo (`metas.despacho.tipo.*`), o rótulo da linha do tempo quando não há natureza. */
    public function rotulo(): string
    {
        return 'metas.despacho.tipo.' . ($this === self::Lembrete ? self::Despacho->value : $this->value);
    }
}
