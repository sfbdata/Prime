<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Decisão 1 do PR 5 (spec mcp-remoto-oauth, seção 14): a resolução da pessoa e do escritório é dupla de propósito, e
 * só o middleware do portão do `/mcp` tranca a requisição. Nenhuma outra classe de `src/` chama
 * `PortaoDoConector::abrir()`: o validador do token, a tela de autorização e o `/oauth/token` usam só as conferências.
 */
#[CoversNothing]
final class PortaoNoMcpArquiteturaTest extends TestCase
{
    private const SRC = __DIR__ . '/../../src';
    private const UNICO = 'Conector/Mcp/PortaoNoMcpMiddleware.php';

    #[TestDox('só o PortaoNoMcpMiddleware chama PortaoDoConector::abrir()')]
    public function testSoOMiddlewareAbreOPortao(): void
    {
        $quem = [];
        foreach ((new Finder())->files()->in(self::SRC)->name('*.php')->sortByName() as $arquivo) {
            $relativo = str_replace('\\', '/', $arquivo->getRelativePathname());
            if ($relativo === 'Conector/Service/PortaoDoConector.php') {
                continue;
            }
            if (self::chamaAbrirDoPortao($arquivo->getContents())) {
                $quem[] = $relativo;
            }
        }

        self::assertSame([self::UNICO], $quem, 'Só o middleware do portão do /mcp abre o portão (decisão 1 do PR 5).');
    }

    #[TestDox('o medidor pega a chamada pela propriedade tipada, nulável, com outro nome e pelo container')]
    public function testMedidorPegaAsFormas(): void
    {
        self::assertTrue(self::chamaAbrirDoPortao('public function __construct(private readonly PortaoDoConector $porta) {} function x() { $this->porta->abrir(1, 2); }'));
        self::assertTrue(self::chamaAbrirDoPortao('function x(?PortaoDoConector $p) { $p?->abrir(1, 2); }'));
        self::assertTrue(self::chamaAbrirDoPortao('function x() { $c->get(PortaoDoConector::class)->abrir(1, 2); }'));
        self::assertTrue(self::chamaAbrirDoPortao('function x(\\App\\Conector\\Service\\PortaoDoConector $p) { $p->abrir(1, 2); }'));
        self::assertFalse(self::chamaAbrirDoPortao('function x(RequisicaoDoConector $r) { $r->abrir($c); }'), 'o abrir da RequisicaoDoConector não conta');
        self::assertFalse(self::chamaAbrirDoPortao('function x(PortaoDoConector $p) { $p->conferir(1, 2); }'));
    }

    /**
     * Chama o `abrir` do portão: por uma variável ou propriedade tipada com `PortaoDoConector`, ou direto sobre um
     * `PortaoDoConector::class` buscado no container.
     */
    private static function chamaAbrirDoPortao(string $codigo): bool
    {
        if (preg_match('/PortaoDoConector::class\)\s*\??->abrir\(/', $codigo) === 1) {
            return true;
        }
        if (preg_match_all('/\??[\w\\\\]*PortaoDoConector\s+\$(\w+)/', $codigo, $nomes) === 0) {
            return false;
        }
        foreach (array_unique($nomes[1]) as $nome) {
            if (preg_match('/\$(this\??->)?' . $nome . '\s*\??->abrir\(/', $codigo) === 1) {
                return true;
            }
        }

        return false;
    }
}
