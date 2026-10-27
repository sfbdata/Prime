<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

/**
 * As abas da tela da pasta que podem ser ocultadas (spec pasta-ocultar-aba §1).
 *
 * O valor é o `id` da aba no cabeçalho (`pasta/_cabecalho.html.twig`) e o id do painel em
 * `pasta/show.html.twig`: `#<valor>-tab` e `#<valor>`. Metas é `tarefas` por causa do legado.
 */
enum AbaDaPasta: string
{
    case Dados      = 'dados';
    case Tarefas    = 'tarefas';
    case Processo   = 'processo';
    case Financeiro = 'financeiro';
    case Detalhes   = 'detalhes';
    case Documentos = 'documentos';
    case Push       = 'push';

    /** O rótulo da aba na tela (o mesmo do cabeçalho), usado no menu, no modal e no aviso. */
    public function rotulo(): string
    {
        return match ($this) {
            self::Dados      => 'Dados da pasta',
            self::Tarefas    => 'Metas',
            self::Processo   => 'Processo',
            self::Financeiro => 'Financeiro',
            self::Detalhes   => 'Modelos',
            self::Documentos => 'Documentos',
            self::Push       => 'Push Processual',
        };
    }
}
