<?php

declare(strict_types=1);

namespace App\Shared\Doctrine\DQL;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * Função DQL CAST_INT_PREFIXO(expr): converte o PREFIXO numérico de uma string
 * para BIGINT, ignorando um eventual sufixo de letra. Ex.: '10' → 10, '10A' → 10,
 * '10B' → 10.
 *
 * Usada para ordenar o NUP de forma natural quando há desambiguação por letra
 * (10, 10A, 10B, 11): o prefixo numérico define a posição e a letra vira desempate
 * (via ORDER BY secundário na coluna crua). Diferente de CAST_INT (que exige a
 * string inteira numérica e retorna NULL para '10A'), aqui só o prefixo importa.
 *
 * Defensiva em dois eixos: (1) valores sem prefixo numérico resultam em NULL,
 * evitando cast inválido (SQLSTATE 22P02); (2) o prefixo é limitado a 18 dígitos
 * antes do CAST, evitando estouro de BIGINT (SQLSTATE 22003) num prefixo absurdamente
 * longo. BIGINT (não INTEGER) porque 18 dígitos não cabem em INTEGER. PostgreSQL apenas.
 */
final class CastIntegerPrefixo extends FunctionNode
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

        return "CASE WHEN {$expr} ~ '^[0-9]' THEN CAST(substring({$expr} FROM '^[0-9]{1,18}') AS BIGINT) ELSE NULL END";
    }
}
