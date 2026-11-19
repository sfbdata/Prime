<?php

declare(strict_types=1);

namespace App\Processo\Enum;

/** Como terminou a consulta de um número de processo ao Datajud (CNJ). */
enum DesfechoDaConsultaAoCnj: string
{
    /** O CNJ devolveu o processo. */
    case Achou = 'achou';

    /** O CNJ respondeu, mas sem nenhum processo com esse número. */
    case NaoEncontrado = 'nao_encontrado';

    /** O número está vazio, fora do padrão CNJ ou de um tribunal que não reconhecemos. */
    case NumeroInvalido = 'numero_invalido';

    /** A consulta falhou (CNJ fora do ar, limite, configuração, erro interno). */
    case Falha = 'falha';
}
