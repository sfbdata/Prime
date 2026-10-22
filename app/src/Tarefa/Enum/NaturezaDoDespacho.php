<?php

declare(strict_types=1);

namespace App\Tarefa\Enum;

/**
 * Natureza do despacho da meta (desenho 04B de 22/10, L159; spec `minhas-metas-esteira-2210.md`, C11): opcional,
 * seleção única. O chip da tela preenche o começo do texto só com o campo vazio ({@see inicioDoTexto()}).
 */
enum NaturezaDoDespacho: string
{
    case Ciencia      = 'ciencia';
    case Providencia  = 'providencia';
    case Manifestacao = 'manifestacao';
    case Conferencia  = 'conferencia';
    case CumpraSe     = 'cumpra_se';

    /** Chave do catálogo (`metas.despacho.natureza.*`); quem exibe aplica `|trans`. */
    public function rotulo(): string
    {
        return 'metas.despacho.natureza.' . $this->value;
    }

    /** Chave do catálogo com a frase que o chip escreve no campo vazio (`metas.despacho.inicio.*`, desenho L159). */
    public function inicioDoTexto(): string
    {
        return 'metas.despacho.inicio.' . $this->value;
    }
}
