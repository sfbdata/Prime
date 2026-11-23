<?php

declare(strict_types=1);

namespace App\Produto\Enum;

/** Situação de uma linha de `contratacao` (spec multiproduto, §4). Religar cria linha nova; a encerrada fica no histórico. */
enum SituacaoDaContratacao: string
{
    case Ativa = 'ativa';
    case Encerrada = 'encerrada';
}
