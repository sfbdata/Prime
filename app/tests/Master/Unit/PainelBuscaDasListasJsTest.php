<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\UseCase\ConsultarAuditoriaUseCase;
use App\Tests\Support\CatalogoPtBr;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Busca das listas longas do Master (pedido M3 do Farlei, 24/11; escopo do Coordenador): o helper
 * public/js/master/bj-busca.js, a busca da trilha de Segurança e auditoria (no servidor, `q`) e a dos Masters (no
 * navegador). Lido como FOLHA no padrão de PainelCodigoEOrdemDasContasJsTest (sem node no PHPUnit).
 */
#[CoversNothing]
final class PainelBuscaDasListasJsTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../../../';

    private static function arquivo(string $caminho): string
    {
        return (string) file_get_contents(self::RAIZ . $caminho);
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

    #[TestDox('o helper tira acento e maiúsculas como o servidor e é carregado antes do painel.js e do masters.js')]
    public function testHelper(): void
    {
        $js = self::arquivo('public/js/master/bj-busca.js');
        self::assertStringContainsString("const normalizar = texto => String(texto == null ? '' : texto).normalize('NFD').replace(/[\\u0300-\\u036f]/g, '').toLowerCase().trim();", $js);
        self::assertStringContainsString('window.BJBusca = { normalizar, termo, casa, atrasar };', $js);
        self::assertStringContainsString('if (window.BJBusca) return;', $js, 'carregar duas vezes não refaz');

        $twig = self::arquivo('templates/master/painel.html.twig');
        $helper = strpos($twig, "asset('js/master/bj-busca.js')");
        self::assertNotFalse($helper);
        self::assertLessThan(strpos($twig, "asset('js/master/painel.js')"), $helper);
        self::assertLessThan(strpos($twig, "asset('js/master/masters.js')"), $helper);
    }

    #[TestDox('trilha: o campo manda `q` ao servidor a partir de 3 letras, com atraso, sem perder o foco, e o PDF diz a busca')]
    public function testBuscaDaTrilha(): void
    {
        $js = self::arquivo('public/js/master/painel.js');
        self::assertSame(3, ConsultarAuditoriaUseCase::BUSCA_MINIMO, 'o mínimo da tela é o do servidor');
        self::assertStringContainsString('const BUSCA_MINIMO = 3;', $js);
        self::assertStringContainsString("S.audTermo ? { q: S.audTermo } : {}", $js, 'a URL da trilha (página, PDF) leva o termo aplicado');
        self::assertStringContainsString("if (e.target.matches('[data-aud-busca]')) { S.audBusca = e.target.value; buscarNaTrilha(); }", $js);
        self::assertStringContainsString('const buscarNaTrilha = window.BJBusca.atrasar(() => {', $js);
        self::assertStringContainsString('if (pedido !== S.audSeq) return;', $js, 'resposta atrasada é descartada');
        self::assertStringContainsString("if (chave === 'audit') return pedirTrilha();", $js, 'a carga da seção, a página, a ação e a conta passam pelo mesmo pedido da busca');
        self::assertStringContainsString("S.audTermo = termo; S.audPag = 1;\n    pedirTrilha();", $js);
        self::assertStringContainsString("S.audConta = +op.dataset.contaId; S.audTipo = ''; S.audBusca = ''; S.audTermo = '';", $js, 'abrir a trilha de uma conta começa sem busca');
        self::assertStringContainsString('if (comFoco && novo) { novo.focus(); novo.setSelectionRange(novo.value.length, novo.value.length); }', $js);
        self::assertStringContainsString("T(S.audTermo ? 'master.js.audit.vazio_busca' : 'master.js.audit.vazio')", $js);
        self::assertStringContainsString("T('master.js.pdf.busca', { termo: esc(S.audTermo) })", $js);

        self::assertSame('Buscar na trilha', self::catalogo('master.js.audit.busca'));
        self::assertSame('Procura em responsável, conta, ação e detalhe, a partir de 3 letras', self::catalogo('master.js.audit.busca_dica'));
        self::assertSame('Nenhum registro encontrado com essa busca.', self::catalogo('master.js.audit.vazio_busca'));
        self::assertSame('busca: {termo}', self::catalogo('master.js.pdf.busca'));
    }

    #[TestDox('Masters: busca no cabeçalho roxo esconde as linhas que não casam; no Promover, esconde as opções do select')]
    public function testBuscaDosMasters(): void
    {
        $twig = self::arquivo('templates/master/painel.html.twig');
        self::assertStringContainsString('<label class="mp-busca"><i class="bi bi-search"></i><input data-mp-masters-busca', $twig);
        self::assertStringContainsString('data-mp-master-busca="{{ m.nome }} {{ m.email }} {{ m.oabNumero }} {{ m.oabUf }}"', $twig);
        self::assertStringContainsString('<div class="mp-vazio-linha" data-mp-masters-nenhum hidden>', $twig);
        self::assertStringContainsString('data-busca="{{ c.nome }} {{ c.email }} {{ c.oabNumero }} {{ c.oabUf }}"', $twig);
        self::assertStringContainsString('<input data-mp-promover-busca', $twig);

        $js = self::arquivo('public/js/master/masters.js');
        self::assertStringContainsString("lin.hidden = !window.BJBusca.casa(lin.getAttribute('data-mp-master-busca'), t);", $js);
        self::assertStringContainsString("if (op.value) op.hidden = op.disabled = op.selected ? false : !window.BJBusca.casa(op.getAttribute('data-busca'), t);", $js, 'o escolhido nunca some');

        $css = self::arquivo('public/css/master.css');
        self::assertStringContainsString('.mp [hidden] { display: none !important; }', $css, 'o hidden vence o display:flex da linha');

        self::assertSame('Buscar Master', self::catalogo('master.painel.masters.busca'));
        self::assertSame('Nenhum Master encontrado.', self::catalogo('master.painel.masters.nenhum_encontrado'));
        self::assertSame('Buscar usuário por nome, e-mail ou OAB', self::catalogo('master.painel.masters.promover_busca'));
    }
}
