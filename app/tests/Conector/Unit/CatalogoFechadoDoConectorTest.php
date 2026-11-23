<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\Enum\TipoDeFerramenta;
use App\Conector\Ferramenta\CatalogoFechadoDoConector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A lista fechada do conector (spec mcp-remoto-oauth 6, 6.2 regra 8, 6.3 item 1, 5.1 e 12.14): 17 ferramentas de escritório (7 de
 * leitura, 9 de gravação e a confirmação) mais as do Painel do Master que já entraram (6.9; hoje a `ler_comercial` e a `automacoes_de_email`); a política de escopo do SDK pede `mcp:escrita` para gravar e confirmar; toda
 * ferramenta tem `title` e as quatro dicas com valor explícito; `_meta` com `securitySchemes`.
 */
#[CoversClass(CatalogoFechadoDoConector::class)]
#[CoversClass(TipoDeFerramenta::class)]
final class CatalogoFechadoDoConectorTest extends TestCase
{
    private const LEITURA = ['quem_sou_eu', 'buscar_pastas', 'ler_pasta', 'ler_documentos_e_modelos', 'ler_processos', 'ler_clientes', 'ler_agenda'];
    /** As duas do Master que já entraram (6.9): ficam no catálogo, depois das 7 de leitura de escritório; a primeira lê, a segunda grava. */
    private const DO_MASTER = ['ler_comercial', 'automacoes_de_email'];
    private const GRAVACAO = ['atualizar_pasta', 'mudar_andamento', 'registrar_na_pasta', 'prazos_e_metas_da_pasta', 'documentos_e_pecas', 'financeiro_da_pasta', 'atualizar_cliente', 'processos_da_pasta', 'gravar_na_agenda'];

    #[TestDox('são exatamente 19 ferramentas: as 7 de leitura da 6.4, a ler_comercial e a automacoes_de_email (só do Master, 6.9), as 9 de gravação da 6.6 e a confirmar_acao')]
    public function testListaFechada(): void
    {
        self::assertCount(19, CatalogoFechadoDoConector::FERRAMENTAS);
        self::assertSame([...self::LEITURA, ...self::DO_MASTER, ...self::GRAVACAO, 'confirmar_acao'], CatalogoFechadoDoConector::nomes());

        $porTipo = array_count_values(array_map(static fn (TipoDeFerramenta $t): string => $t->value, CatalogoFechadoDoConector::FERRAMENTAS));
        self::assertSame(['leitura' => 8, 'gravacao' => 10, 'confirmacao' => 1], $porTipo);
        foreach (self::GRAVACAO as $nome) {
            self::assertSame(TipoDeFerramenta::Gravacao, CatalogoFechadoDoConector::tipo($nome), $nome);
        }
        self::assertSame(TipoDeFerramenta::Confirmacao, CatalogoFechadoDoConector::tipo('confirmar_acao'));
        self::assertSame(CatalogoFechadoDoConector::nomes(), array_keys(CatalogoFechadoDoConector::TITULOS), 'um título por ferramenta, na mesma ordem');
        self::assertSame([], array_diff(CatalogoFechadoDoConector::MUNDO_ABERTO, CatalogoFechadoDoConector::nomes()));
    }

    #[TestDox('ferramenta fora da lista não existe e não tem tipo, anotação nem escopo')]
    public function testForaDaLista(): void
    {
        self::assertFalse(CatalogoFechadoDoConector::existe('consultar_sql'));
        self::assertTrue(CatalogoFechadoDoConector::existe('quem_sou_eu'));
        self::assertTrue(CatalogoFechadoDoConector::existe('ler_comercial'));
        self::assertTrue(CatalogoFechadoDoConector::soDoMaster('ler_comercial'));
        self::assertTrue(CatalogoFechadoDoConector::existe('automacoes_de_email'));
        self::assertSame(TipoDeFerramenta::Gravacao, CatalogoFechadoDoConector::tipo('automacoes_de_email'));

        $this->expectException(\InvalidArgumentException::class);
        CatalogoFechadoDoConector::anotacoes('consultar_sql');
    }

    #[TestDox('a política de escopo pede mcp:leitura para ler e mcp:leitura com mcp:escrita para gravar e confirmar')]
    public function testPoliticaDeEscopo(): void
    {
        $politica = CatalogoFechadoDoConector::politicaDeEscopo();

        self::assertTrue($politica->inspectsBody());
        self::assertSame(['mcp:leitura'], $politica->requiredFor(['method' => 'tools/list']));
        self::assertSame(['mcp:leitura'], $politica->requiredFor(null), 'requisição sem corpo JSON-RPC: o mínimo');
        foreach ([...self::LEITURA, 'ler_comercial'] as $nome) {
            self::assertSame(['mcp:leitura'], $politica->requiredFor(self::chamada($nome)), $nome);
            self::assertSame(['mcp:leitura'], CatalogoFechadoDoConector::escoposExigidos($nome), $nome);
        }
        foreach ([...self::GRAVACAO, 'automacoes_de_email', 'confirmar_acao'] as $nome) {
            self::assertSame(['mcp:leitura', 'mcp:escrita'], $politica->requiredFor(self::chamada($nome)), $nome);
            self::assertSame(['mcp:leitura', 'mcp:escrita'], CatalogoFechadoDoConector::escoposExigidos($nome), $nome);
        }
        // Escrita não é implicada por nada: o token só de leitura fica sem ela.
        self::assertSame(['mcp:leitura'], $politica->expand(['mcp:leitura']));
    }

    #[TestDox('anotações explícitas: title em português e as quatro dicas, pelo tipo e pelo mundo aberto')]
    public function testAnotacoes(): void
    {
        foreach (CatalogoFechadoDoConector::nomes() as $nome) {
            $json = CatalogoFechadoDoConector::anotacoes($nome)->jsonSerialize();

            self::assertSame(['title', 'readOnlyHint', 'destructiveHint', 'idempotentHint', 'openWorldHint'], array_keys($json), $nome);
            self::assertSame(CatalogoFechadoDoConector::TITULOS[$nome], $json['title']);
            self::assertNotSame('', trim($json['title']));
            self::assertSame(\in_array($nome, ['ler_processos', 'gravar_na_agenda'], true), $json['openWorldHint'], $nome);
        }

        $leitura = CatalogoFechadoDoConector::anotacoes('ler_pasta');
        self::assertTrue($leitura->readOnlyHint);
        self::assertFalse($leitura->destructiveHint);
        self::assertFalse($leitura->openWorldHint);

        foreach (['atualizar_pasta', 'gravar_na_agenda', 'confirmar_acao'] as $nome) {
            $gravacao = CatalogoFechadoDoConector::anotacoes($nome);
            self::assertFalse($gravacao->readOnlyHint, $nome);
            self::assertTrue($gravacao->destructiveHint, $nome);
            self::assertFalse($gravacao->idempotentHint, $nome);
        }
    }

    #[TestDox('o _meta de toda ferramenta traz os securitySchemes do ChatGPT; só a confirmar_acao pede interação ao Claude')]
    public function testMeta(): void
    {
        self::assertSame(
            ['securitySchemes' => [['type' => 'oauth2', 'scopes' => ['mcp:leitura']]]],
            CatalogoFechadoDoConector::meta('quem_sou_eu'),
        );
        self::assertSame(
            ['securitySchemes' => [['type' => 'oauth2', 'scopes' => ['mcp:leitura', 'mcp:escrita']]]],
            CatalogoFechadoDoConector::meta('atualizar_pasta'),
        );
        self::assertSame(
            ['securitySchemes' => [['type' => 'oauth2', 'scopes' => ['mcp:leitura', 'mcp:escrita']]], 'anthropic/requiresUserInteraction' => true],
            CatalogoFechadoDoConector::meta('confirmar_acao'),
        );
        foreach (CatalogoFechadoDoConector::nomes() as $nome) {
            self::assertArrayHasKey('securitySchemes', CatalogoFechadoDoConector::meta($nome), $nome);
            self::assertSame($nome === 'confirmar_acao', \array_key_exists('anthropic/requiresUserInteraction', CatalogoFechadoDoConector::meta($nome)), $nome);
        }
    }

    /** @return array{jsonrpc: string, id: int, method: string, params: array{name: string, arguments: array<never>}} */
    private static function chamada(string $nome): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $nome, 'arguments' => []]];
    }
}
