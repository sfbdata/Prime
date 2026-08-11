<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Functional;

use App\Mcp\Service\ConexaoLeitura;
use App\Mcp\Tool\DescreverEsquemaTool;
use PHPUnit\Framework\TestCase;

final class DescreverEsquemaToolTest extends TestCase
{
    private function ferramenta(): DescreverEsquemaTool
    {
        $dsn = $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');
        self::assertIsString($dsn);

        return new DescreverEsquemaTool(new ConexaoLeitura($dsn));
    }

    public function testSemArgumentoListaAsTabelas(): void
    {
        $resultado = $this->ferramenta()->descrever();

        self::assertContains('tenant', $resultado['tabelas']);
        self::assertContains('cliente', $resultado['tabelas']);
        self::assertArrayNotHasKey('colunas', $resultado);
    }

    public function testDescreveColunasDeUmaTabela(): void
    {
        $resultado = $this->ferramenta()->descrever('cliente');

        self::assertSame('cliente', $resultado['tabela']);

        $porNome = array_column($resultado['colunas'], null, 'coluna');

        self::assertArrayHasKey('email', $porNome, 'coluna email sumiu da tabela cliente');
        self::assertSame('NAO', $porNome['email']['aceita_nulo']);
        self::assertArrayHasKey('tenant_id', $porNome);
    }

    public function testTrazOsIndicesDaTabela(): void
    {
        $resultado = $this->ferramenta()->descrever('cliente');

        self::assertNotEmpty($resultado['indices']);
    }

    public function testTabelaInexistenteFalhaComMensagemClara(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/não existe/');

        $this->ferramenta()->descrever('tabela_que_nao_existe_nenhuma');
    }
}
