<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Ferramenta\ConfirmarAcaoFerramenta;
use App\Conector\Ferramenta\DespachoDasFerramentas;
use App\Conector\Ferramenta\FerramentaDoConector;
use App\Conector\Service\RequisicaoDoConector;
use App\Tests\Conector\Support\DublesDoMcpTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A `confirmar_acao` no `/mcp` (spec mcp-remoto-oauth 5.1, 6.2 regra 8, 6.7 e 12.5, 12.14 e 12.20): o container a
 * registra; no `tools/list` ela sai com o esquema de id e resumo, `destructiveHint` e o pedido de interação do Claude;
 * sem `mcp:escrita`, 403 `insufficient_scope` antes do portão; e nenhuma classe do conector pede elicitation.
 */
#[CoversClass(ConfirmarAcaoFerramenta::class)]
final class ConfirmarAcaoNoMcpTest extends KernelTestCase
{
    use DublesDoMcpTrait;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    #[TestDox('o container registra a confirmar_acao entre as ferramentas do despacho')]
    public function testRegistradaNoContainer(): void
    {
        $nomes = array_map(static fn (FerramentaDoConector $f): string => $f->nome(), static::getContainer()->get(DespachoDasFerramentas::class)->registradas());

        self::assertContains(ConfirmarAcaoFerramenta::NOME, $nomes);
    }

    #[TestDox('12.14: no tools/list, só proposta_id e resumo (obrigatórios, nada além), destructiveHint, não idempotente e anthropic/requiresUserInteraction')]
    public function testNoToolsList(): void
    {
        $confirmar = static::getContainer()->get(ConfirmarAcaoFerramenta::class);
        $resposta = $this->servidor(['mcp:leitura'], [$confirmar], new RequisicaoDoConector())->responder(self::post('tools/list'));

        $ferramenta = self::corpo($resposta)['result']['tools'][0];
        self::assertSame('confirmar_acao', $ferramenta['name']);
        self::assertSame('Confirmar a ação proposta', $ferramenta['title']);
        self::assertSame(['proposta_id', 'resumo'], array_keys($ferramenta['inputSchema']['properties']));
        self::assertSame(['proposta_id', 'resumo'], $ferramenta['inputSchema']['required']);
        self::assertFalse($ferramenta['inputSchema']['additionalProperties']);
        self::assertFalse($ferramenta['annotations']['readOnlyHint']);
        self::assertTrue($ferramenta['annotations']['destructiveHint']);
        self::assertFalse($ferramenta['annotations']['idempotentHint']);
        self::assertTrue($ferramenta['_meta']['anthropic/requiresUserInteraction']);
        self::assertSame([['type' => 'oauth2', 'scopes' => ['mcp:leitura', 'mcp:escrita']]], $ferramenta['_meta']['securitySchemes']);
        self::assertArrayNotHasKey('outputSchema', $ferramenta);
    }

    #[TestDox('12.5: sem mcp:escrita, a confirmar_acao recebe 403 insufficient_scope antes do portão e não roda')]
    public function testSemEscritaDa403(): void
    {
        $confirmar = self::ferramenta('confirmar_acao', new RespostaDaFerramenta(['estado' => 'confirmada']), ['proposta_id' => ['type' => 'string'], 'resumo' => ['type' => 'string']]);

        $resposta = $this->servidor(['mcp:leitura'], [$confirmar], new RequisicaoDoConector())
            ->responder(self::post('tools/call', ['name' => 'confirmar_acao', 'arguments' => ['proposta_id' => str_repeat('a', 43), 'resumo' => 'x']]));

        self::assertSame(403, $resposta->getStatusCode());
        self::assertStringContainsString('error="insufficient_scope"', $resposta->getHeaderLine('WWW-Authenticate'));
        self::assertStringContainsString('scope="mcp:leitura mcp:escrita"', $resposta->getHeaderLine('WWW-Authenticate'));
        self::assertSame(0, $this->passouNoPortao);
        self::assertSame([], $confirmar->recebidos);
    }

    #[TestDox('6.7, item 2: nenhuma classe do conector pede elicitation, sampling ou roots ao app (sem pedido do servidor ao cliente)')]
    public function testSemElicitation(): void
    {
        $pasta = \dirname(__DIR__, 3) . '/src/Conector';
        $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($pasta, \FilesystemIterator::SKIP_DOTS));
        $achados = [];
        foreach ($iterador as $arquivo) {
            if (!$arquivo instanceof \SplFileInfo || $arquivo->getExtension() !== 'php') {
                continue;
            }
            $codigo = (string) file_get_contents($arquivo->getPathname());
            if (preg_match('/->\s*(elicit|sample|listRoots)\s*\(|\$gateway\s*->|elicitation\/create|ElicitRequest|CreateSamplingMessageRequest/', $codigo) === 1) {
                $achados[] = $arquivo->getFilename();
            }
        }

        self::assertSame([], $achados);
    }
}
