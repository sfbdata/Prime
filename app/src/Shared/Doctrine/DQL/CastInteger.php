<?php

declare(strict_types=1);

namespace App\Shared\Doctrine\DQL;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * Função DQL que converte uma expressão textual para inteiro: CAST_INT(expr).
 *
 * Usada para ordenar colunas VARCHAR cujo conteúdo é numérico (ex.: nup) em
 * ordem numérica, sem alterar o valor exibido. A conversão é defensiva: só
 * valores compostos exclusivamente por dígitos são convertidos; qualquer outro
 * conteúdo resulta em NULL, evitando erro de cast (SQLSTATE 22P02) caso um
 * valor não-numérico exista na coluna. PostgreSQL apenas.
 *
 * O cast é para BIGINT (até 19 dígitos), não INTEGER, para não estourar com
 * NUPs longos (INTEGER satura em ~2,1 bilhões / 10 dígitos). O nome CAST_INT é
 * mantido como alias da função registrada.
 */
final class CastInteger extends FunctionNode
{
    private ?Node $expressao = null;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->expressao = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        $expr = $this->expressao->dispatch($sqlWalker);

        return "CASE WHEN {$expr} ~ '^[0-9]+\$' THEN CAST({$expr} AS BIGINT) ELSE NULL END";
    }
}
