<?php

declare(strict_types=1);

namespace App\Tarefa\Enum;

/**
 * De onde veio o prazo jurídico da análise do prazo da meta (desenho 02, `IA_PRAZO`, dc 4004-4007). Os
 * rótulos de tela ficam de fora DE PROPÓSITO: o desenho escreve "Estimado pela I.A." numa regra, e o rótulo
 * está com o dono (regra 1 da Inteligência e decisão S-4). Quem desenhar a tela escolhe o texto.
 */
enum EstadoDoPrazoJuridico: string
{
    /** A pessoa informou a data (no campo ou escrita no texto da meta). */
    case Informado = 'informado';
    /** A pessoa confirmou a análise: novas estimativas não trocam mais a data. */
    case Confirmado = 'confirmado';
    /** Contado a partir da ciência ou da publicação informadas, pela tabela do CPC. */
    case Estimado = 'estimado';
    /** Sem termo inicial: contado a partir de hoje, só como aproximação. */
    case NecessitaConferencia = 'necessita_conferencia';
    /** O tipo de providência não tem prazo legal. */
    case SemPrazoLegal = 'sem_prazo_legal';
}
