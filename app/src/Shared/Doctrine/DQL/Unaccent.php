<?php

declare(strict_types=1);

namespace App\Shared\Doctrine\DQL;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * Função DQL UNACCENT(expr): remove acentos/diacríticos (á→a, ã→a, ç→c) via a
 * extensão `unaccent` do PostgreSQL. Combinada com LOWER, torna a busca livre
 * dos filtros tolerante a acento e maiúsculas — quem não sabe a grafia exata
 * encontra assim mesmo (ex.: "sao" acha "São", "goncalves" acha "Gonçalves").
 *
 * PostgreSQL apenas; requer a extensão unaccent instalada (ver a migration
 * Version20260703120000). O nome UNACCENT é o alias registrado em doctrine.yaml.
 */
final class Unaccent extends FunctionNode
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
        return 'unaccent(' . $this->expressao->dispatch($sqlWalker) . ')';
    }
}
