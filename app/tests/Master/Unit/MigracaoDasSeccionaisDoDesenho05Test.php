<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A migration do alinhamento das seccionais ao desenho 05 (Version20261020149500) só acrescenta: a guarda do deploy
 * para em DROP, ALTER TYPE e DROP/SET NOT NULL de coluna existente. A tabela das divulgações fica intocada (obsoleta).
 */
final class MigracaoDasSeccionaisDoDesenho05Test extends TestCase
{
    private static function up(): string
    {
        $codigo = (string) file_get_contents(__DIR__ . '/../../../migrations/Version20261020149500.php');
        $inicio = strpos($codigo, 'public function up(');
        $fim = strpos($codigo, 'public function down(');
        self::assertNotFalse($inicio);
        self::assertNotFalse($fim);

        return substr($codigo, $inicio, $fim - $inicio);
    }

    #[TestDox('o up() só tem ADD COLUMN, CREATE TABLE, CREATE INDEX e ADD CONSTRAINT da tabela nova')]
    public function testUpSoAcrescenta(): void
    {
        $up = self::up();

        self::assertDoesNotMatchRegularExpression('/\bDROP\b/i', $up);
        self::assertDoesNotMatchRegularExpression('/\bTYPE\b|\bSET NOT NULL\b|\bRENAME\b/i', $up);
        self::assertStringNotContainsString('master_divulgacao_seccional', $up, 'a tabela das divulgações fica como está');
        preg_match_all("/addSql\\('([^']*(?:\\\\'[^']*)*)'\\)/", $up, $m);
        self::assertNotEmpty($m[1]);
        foreach ($m[1] as $sql) {
            self::assertMatchesRegularExpression('/^(ALTER TABLE master_\w+ ADD (?!CONSTRAINT)\w+ [^;]*(DEFAULT NULL|DEFAULT true NOT NULL)$|CREATE TABLE master_nota_cidade_oab |CREATE (UNIQUE )?INDEX |ALTER TABLE master_nota_cidade_oab ADD CONSTRAINT )/', $sql);
        }
    }
}
