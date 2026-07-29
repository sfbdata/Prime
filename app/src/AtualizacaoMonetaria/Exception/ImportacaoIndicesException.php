<?php

declare(strict_types=1);

namespace App\AtualizacaoMonetaria\Exception;

/**
 * Falha ao obter os índices oficiais no Banco Central.
 *
 * Cobre tanto a rede (indisponibilidade, timeout, status de erro) quanto o conteúdo (corpo que não
 * é JSON, registro sem data/valor, valor não numérico, competência repetida). A importação inteira
 * da série é abortada: um índice errado vira dinheiro errado no cálculo, e meia série gravada é
 * pior do que série nenhuma.
 */
final class ImportacaoIndicesException extends \RuntimeException
{
}
