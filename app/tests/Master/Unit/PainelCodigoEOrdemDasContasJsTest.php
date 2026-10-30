<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Tests\Support\CatalogoPtBr;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Código da conta, "Ordem:" e busca por código na lista de "Contas e escritórios" (desenho 05 - Perfis 1.2 - VS
 * 30.10.26, L173-L181), lidos como FOLHA no padrão de PainelNumeroDaContaJsTest (sem node no PHPUnit): o contrato do
 * public/js/master/painel.js, do public/css/master.css e do catálogo com o desenho.
 */
#[CoversNothing]
final class PainelCodigoEOrdemDasContasJsTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../../../';

    private static function js(): string
    {
        return (string) file_get_contents(self::RAIZ . 'public/js/master/painel.js');
    }

    private static function css(): string
    {
        return (string) file_get_contents(self::RAIZ . 'public/css/master.css');
    }

    private static function catalogo(string $chave): string
    {
        $no = CatalogoPtBr::carregar();
        foreach (explode('.', $chave) as $parte) {
            self::assertIsArray($no, $chave);
            self::assertArrayHasKey($parte, $no, $chave);
            $no = $no[$parte];
        }
        self::assertIsString($no, $chave);

        return $no;
    }

    #[TestDox('o código é um chip FILHO DIRETO da linha de ids, ao lado do Nº, com o title e o CSS do desenho (#0a6fa0 sobre #e5f1f7)')]
    public function testChipDoCodigo(): void
    {
        $js = self::js();
        self::assertStringContainsString("'<span class=\"mp-conta-codigo\" title=\"' + esc(T('master.js.contas.codigo_dica')) + '\">' + esc(c.codigo) + '</span>'", $js);
        self::assertStringContainsString("(c.codigo ? '<span class=\"mp-conta-codigo\"", $js, 'conta sem código não leva chip vazio');
        self::assertSame('Código da conta', self::catalogo('master.js.contas.codigo_dica'));

        $css = self::css();
        // Arranjo por filho direto: a linha de ids é filha da célula, o Nº e o chip são filhos da linha de ids.
        self::assertStringContainsString('.mp-conta-ids { display: flex; align-items: center; gap: 6px; min-width: 0; }', $css);
        self::assertStringContainsString('.mp-conta-codigo { flex: none; font: bold 10.5px/1 monospace; letter-spacing: .5px; color: #0a6fa0; background: #e5f1f7; border: 1px solid #cfe3f0; border-radius: 3px; padding: 2px 5px; }', $css);
        self::assertMatchesRegularExpression('/<span class="mp-conta-ids"><span class="mp-conta-nro"[^>]*>.*?<\/span>\'\s*\+ \(c\.codigo \? \'<span class="mp-conta-codigo"/s', $js, 'Nº e código lado a lado, nesta ordem');
    }

    #[TestDox('select "Ordem:" com as 6 opções do desenho, "nº da conta" padrão, e cada ordem com a regra do desenho')]
    public function testOrdem(): void
    {
        $js = self::js();
        self::assertStringContainsString("cOrd: 'num'", $js, 'padrão: nº da conta');
        self::assertStringContainsString('data-c-ord', $js);
        self::assertStringContainsString("if (t.matches('[data-c-ord]')) { S.cOrd = t.value;", $js);
        self::assertSame(
            ['Ordem: nº da conta', 'Ordem: nome (A-Z)', 'Ordem: mais usuários', 'Ordem: mais armazenamento', 'Ordem: mais pastas', 'Ordem: mais recentes'],
            array_map(self::catalogo(...), ['master.js.contas.ordem_num', 'master.js.contas.ordem_nome', 'master.js.contas.ordem_usu', 'master.js.contas.ordem_arm', 'master.js.contas.ordem_pastas', 'master.js.contas.ordem_rec']),
        );
        foreach (['num', 'nome', 'usu', 'arm', 'pastas', 'rec'] as $chave) {
            self::assertStringContainsString("['" . $chave . "', () => T('master.js.contas.ordem_" . $chave . "')]", $js);
        }
        self::assertStringContainsString("nome: (a, b) => a.nome.localeCompare(b.nome, 'pt-BR'),", $js);
        self::assertStringContainsString('usu: (a, b) => b.usuarios - a.usuarios,', $js);
        self::assertStringContainsString('arm: (a, b) => b.bytes - a.bytes,', $js);
        self::assertStringContainsString('pastas: (a, b) => b.pastas - a.pastas,', $js);
        self::assertStringContainsString('rec: (a, b) => Date.parse(b.criadoEm) - Date.parse(a.criadoEm),', $js);
        self::assertStringContainsString('num: (a, b) => a.id - b.id,', $js, 'o Nº é o id: ordem de criação');
        self::assertStringContainsString('|| a.id - b.id);', $js, 'empate pelo nº');
        self::assertStringContainsString('ordenarContas(D.contas.contas.filter(', $js);
    }

    #[TestDox('busca por código: minúsculas e sem hífens; sem estado "Teste" no filtro (o sistema não tem)')]
    public function testBuscaPorCodigoEFiltro(): void
    {
        $js = self::js();
        self::assertStringContainsString("const sh = b.replace(/-/g, '');", $js);
        self::assertStringContainsString("if (sh && c.codigo && c.codigo.toLowerCase().replace(/-/g, '').includes(sh)) return true;", $js);
        self::assertStringContainsString('[ATIVA, SUSPENSA].map(', $js);
        self::assertStringNotContainsString("'Teste'", $js);
    }
}
