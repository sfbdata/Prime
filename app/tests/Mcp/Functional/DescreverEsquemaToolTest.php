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

    /**
     * A spec §4.2 promete "colunas, tipos, nulidade, CHAVES e índices". PK e UNIQUE dava para
     * inferir do `indexdef`; FK não aparecia de jeito nenhum — e é exatamente o que o modelo
     * precisa para escrever um JOIN certo num esquema multi-tenant, que é o motivo declarado de
     * a ferramenta existir.
     *
     * A asserção afirma CONTEÚDO, não "veio não-vazio": a FK de `cliente` para `tenant` é real,
     * conhecida e estável. Se o retorno voltar a ser genérico, este teste fica vermelho.
     */
    public function testTrazAsChavesDaTabelaInclusiveAsEstrangeiras(): void
    {
        $resultado = $this->ferramenta()->descrever('cliente');

        $definicoes = array_column($resultado['chaves'], 'definicao');

        self::assertContains(
            'FOREIGN KEY (tenant_id) REFERENCES tenant(id)',
            $definicoes,
            'a FK de cliente para tenant sumiu do retorno de descrever_esquema',
        );
        self::assertContains('PRIMARY KEY (id)', $definicoes);

        $porDefinicao = array_column($resultado['chaves'], 'tipo', 'definicao');
        self::assertSame('FOREIGN KEY', $porDefinicao['FOREIGN KEY (tenant_id) REFERENCES tenant(id)']);
        self::assertSame('PRIMARY KEY', $porDefinicao['PRIMARY KEY (id)']);
    }

    public function testTabelaInexistenteFalhaComMensagemClara(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/não existe/');

        $this->ferramenta()->descrever('tabela_que_nao_existe_nenhuma');
    }

    /**
     * Prova 4 da spec voto-redes-do-schema. A role de teste não tem permissão nenhuma no schema `voto`, igual à de
     * produção (a premissa é conferida aqui mesmo): a lista e a descrição vêm do catálogo, que mostra a tabela assim
     * mesmo. Usa a `voto.liberacao` real.
     */
    public function testListaEDescreveATabelaDoSchemaVotoSemPermissaoNele(): void
    {
        $conexao = new ConexaoLeitura(BancoDeLeituraDeTeste::dsnLeitura());
        $usa     = $conexao->consultar("SELECT has_schema_privilege('voto', 'USAGE') AS usa", 1)['linhas'];
        self::assertSame([['usa' => false]], $usa, 'premissa: a role de leitura não tem USAGE no schema voto');

        $ferramenta = new DescreverEsquemaTool($conexao);
        self::assertContains('voto.liberacao', $ferramenta->descrever()['tabelas']);

        $resultado = $ferramenta->descrever('voto.liberacao');

        self::assertSame('voto.liberacao', $resultado['tabela']);
        self::assertArrayHasKey('tenant_id', array_column($resultado['colunas'], null, 'coluna'));
        self::assertContains(
            'FOREIGN KEY (tenant_id) REFERENCES tenant(id) ON DELETE CASCADE',
            array_column($resultado['chaves'], 'definicao'),
        );
        self::assertContains('uniq_voto_liberacao_tenant', array_column($resultado['indices'], 'indice'));
    }

    public function testTabelaInexistenteNoSchemaVotoContinuaRecusada(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessageMatches('/não existe/');

        $this->ferramenta()->descrever('voto.nao_existe');
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
