<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Functional;

use App\Mcp\Service\ConexaoLeitura;
use App\Mcp\Tool\DescreverEsquemaTool;
use App\Tests\Mcp\BancoDeLeituraDeTeste;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;

final class DescreverEsquemaToolTest extends TestCase
{
    private function ferramenta(): DescreverEsquemaTool
    {
        // Role restrita, banco DA FRENTE. As asserções abaixo afirmam ESQUEMA — com o
        // `$_ENV['DATABASE_URL']` cru elas valeriam contra `saas` (o banco de dev), e uma
        // divergência de migração introduzida nesta branch passaria batida.
        return new DescreverEsquemaTool(new ConexaoLeitura(BancoDeLeituraDeTeste::dsnLeitura()));
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

    /**
     * `listarTabelas()`/`colunas()`/`indices()` chamam `ConexaoLeitura::consultar()` sem
     * `try` próprio — antes desta correção, uma falha de conexão escapava como
     * `\RuntimeException` cru, não `ToolCallException`, e viraria erro de PROTOCOLO no
     * servidor MCP (ver `McpServerCommand`/Task 5), não `isError` seguro. DSN vazio reproduz
     * a falha de conexão sem precisar derrubar o banco de teste.
     */
    public function testFalhaDeConexaoViraErroDeFerramenta(): void
    {
        $ferramenta = new DescreverEsquemaTool(new ConexaoLeitura(''));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessageMatches('/Falha ao descrever o esquema/');

        $ferramenta->descrever();
    }
}
