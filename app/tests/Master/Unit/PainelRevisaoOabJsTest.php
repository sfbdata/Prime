<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Tests\Support\CatalogoPtBr;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Revisão de OAB no painel do Master (desenho 05 VS 31.10.26: SECS, secRoab/secRoabSo e roabVals), lida como FOLHA no
 * padrão de PainelRecompensasJsTest: sem navegador no PHPUnit, prova-se o contrato do revisao-oab.js, do painel.js, do
 * template, do CSS e do catálogo com o desenho. O texto mora no catálogo (master.js.revisao_oab.*).
 */
#[CoversNothing]
final class PainelRevisaoOabJsTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../../../';

    private static function arquivo(string $caminho): string
    {
        return (string) file_get_contents(self::RAIZ . $caminho);
    }

    private static function js(): string
    {
        return self::arquivo('public/js/master/revisao-oab.js');
    }

    private static function catalogo(string $chave): mixed
    {
        $no = CatalogoPtBr::carregar();
        foreach (explode('.', $chave) as $parte) {
            self::assertIsArray($no, $chave);
            self::assertArrayHasKey($parte, $no, $chave);
            $no = $no[$parte];
        }

        return $no;
    }

    #[TestDox('painel.js: a seção roab entra por último, depois da notif, com o rótulo e o ícone do desenho')]
    public function testSecaoRoabPorUltimo(): void
    {
        $js = self::arquivo('public/js/master/painel.js');
        $notif = strpos($js, "if (EXTERNAS.notif) SECS.push(['notif'");
        $roab = strpos($js, "SECS.push(['roab', T('master.js.secao.roab'), 'bi-patch-check']);");

        self::assertNotFalse($notif);
        self::assertNotFalse($roab);
        self::assertGreaterThan($notif, $roab);
        self::assertSame(1, preg_match_all('/SECS\.push\(/', substr($js, $roab)), 'nenhuma seção depois da roab');
        self::assertSame('Revisão de OAB', self::catalogo('master.js.secao.roab'));
    }

    #[TestDox('o mesmo bloco abre na seção roab e embutido em Usuários e OABs, depois da nota, sem tirar o link da fila antiga')]
    public function testBlocoNasDuasSecoes(): void
    {
        $js = self::arquivo('public/js/master/painel.js');

        self::assertStringContainsString("if (sec === 'roab') { elSecao.innerHTML = '<div id=\"mp-roab\"></div>'; return montarRevisaoOab(); }", $js);
        $nota = strpos($js, "T('master.js.usuarios_oab.nota') + ' <a href=\"' + esc(URLS.revisaoOab) + '\">'");
        $embutido = strpos($js, "'<div class=\"roab-embutido\" id=\"mp-roab\"></div>'");
        self::assertNotFalse($nota);
        self::assertNotFalse($embutido);
        self::assertGreaterThan($nota, $embutido);
        self::assertStringContainsString('window.BJRevisaoOab.montar(el, {', $js);
        self::assertStringContainsString('.mp .roab-embutido { margin-top: 22px; }', self::arquivo('public/css/master.css'));
    }

    #[TestDox('template: URLs dos endpoints, token master_revisao_oab e o script antes do painel.js')]
    public function testTemplate(): void
    {
        $twig = self::arquivo('templates/master/painel.html.twig');

        foreach (['master_revisao_oab_listar', 'master_revisao_oab_confirmar', 'master_revisao_oab_conferir', 'master_revisao_oab_automatica', 'master_revisao_oab_validar_agora'] as $rota) {
            self::assertStringContainsString("path('" . $rota . "'", $twig);
        }
        self::assertStringContainsString("roabToken: csrf_token('master_revisao_oab')", $twig);
        self::assertStringContainsString("revisaoOab: path('admin_platform_oab_index')", $twig);
        $modulo = strpos($twig, "asset('js/master/revisao-oab.js')");
        self::assertNotFalse($modulo);
        self::assertLessThan(strpos($twig, "asset('js/master/painel.js')"), $modulo);
    }

    #[TestDox('toda chave T(...) do revisao-oab.js existe no catálogo, inclusive as montadas (abas, status, frequências, colunas)')]
    public function testChavesNoCatalogo(): void
    {
        preg_match_all("/\\bT\\('([a-z_.]+)'/", self::js(), $m);
        self::assertGreaterThan(20, count(array_unique($m[1])));
        foreach (array_unique($m[1]) as $chave) {
            if (str_ends_with($chave, '.')) {
                continue;
            }
            self::assertIsString(self::catalogo('master.js.revisao_oab.' . $chave), $chave);
        }
        foreach (['aba.pend', 'aba.div', 'aba.nao', 'aba.conf', 'aba.todas', 'status.conf', 'status.div', 'status.nao', 'status.pend', 'freq.cadastro', 'freq.diaria', 'freq.semanal'] as $chave) {
            self::assertIsString(self::catalogo('master.js.revisao_oab.' . $chave), $chave);
        }
    }

    #[TestDox('textos do desenho: título, abas, cabeçalho NOME..AÇÕES, vazio, títulos dos botões, busca, dica do desabilitado')]
    public function testTextosDoDesenho(): void
    {
        $esperado = [
            'titulo' => 'Revisão de OAB',
            'subtitulo' => 'Identidade global: advogados de todos os escritórios',
            'total' => '{n} inscrições',
            'total_um' => '{n} inscrição',
            'auto_ligada' => 'Validação automática ligada',
            'auto_desligada' => 'Validação automática desligada',
            'freq.cadastro' => 'Ao cadastrar a OAB',
            'freq.diaria' => 'Diariamente',
            'freq.semanal' => 'Semanalmente',
            'freq_sem_cna' => 'Disponível quando a consulta ao CNA estiver ligada',
            'ajuda' => 'Confere formato, nome no CNA e situação. Nome igual confirma; nome diferente vai para Divergentes.',
            'ultima_rodada' => 'Última rodada: {quando}',
            'ainda_nao_rodou' => 'Ainda não rodou',
            'validar_agora' => 'Validar agora',
            'aba.pend' => 'Pendentes',
            'aba.div' => 'Divergentes',
            'aba.nao' => 'Não verificadas',
            'aba.conf' => 'Confirmadas',
            'aba.todas' => 'Todas',
            'busca' => 'Nome, e-mail ou nº OAB',
            'col.nome' => 'NOME',
            'col.escritorio' => 'ESCRITÓRIO',
            'col.oab' => 'OAB',
            'col.nome_cna' => 'NOME NO CNA',
            'col.status' => 'STATUS',
            'col.verificada_em' => 'VERIFICADA EM',
            'col.acoes' => 'AÇÕES',
            'vazio' => 'Nenhuma inscrição nesta aba.',
            'principal' => 'PRINCIPAL',
            'nao_informado' => 'Não informado',
            'sem_registro' => 'Sem registro',
            'status.conf' => 'Confirmada',
            'status.div' => 'Divergente',
            'status.nao' => 'Não verificada',
            'status.pend' => 'Pendente',
            'confirmar' => 'Confirmar inscrição',
            'verificar' => 'Verificar de novo (nome no CNA)',
            'prompt' => 'Nome que consta no CNA para a OAB {inscricao} (deixe em branco se não localizar):',
        ];
        foreach ($esperado as $chave => $texto) {
            self::assertSame($texto, self::catalogo('master.js.revisao_oab.' . $chave), $chave);
        }
        $cab = array_map(static fn (string $k): string => (string) self::catalogo('master.js.revisao_oab.col.' . $k), ['nome', 'escritorio', 'oab', 'nome_cna', 'status', 'verificada_em', 'acoes']);
        self::assertSame(['NOME', 'ESCRITÓRIO', 'OAB', 'NOME NO CNA', 'STATUS', 'VERIFICADA EM', 'AÇÕES'], $cab);
        self::assertStringContainsString("['nome', 'escritorio', 'oab', 'nome_cna', 'status', 'verificada_em']", self::js());
    }

    #[TestDox('Diariamente e Semanalmente aparecem desabilitadas, com a dica; só Ao cadastrar a OAB fica habilitada')]
    public function testFrequenciasDesabilitadas(): void
    {
        $js = self::js();

        self::assertStringContainsString("const FREQS = ['cadastro', 'diaria', 'semanal'];", $js);
        self::assertStringContainsString("(f === 'cadastro' ? '' : ' disabled title=\"' + esc(T('freq_sem_cna')) + '\"')", $js);
    }

    #[TestDox('ações: Confirmar sempre na linha, Verificar de novo pelo prompt com nomeCna ou nome, POST com X-CSRF-Token e recarga')]
    public function testAcoes(): void
    {
        $js = self::js();

        self::assertStringContainsString("'X-CSRF-Token': ctx.token", $js);
        self::assertStringContainsString("window.prompt(T('prompt', { inscricao: l.inscricao }), l.nomeCna || l.nome) || '').trim()", $js);
        self::assertStringContainsString('{ numero: l.numero, uf: l.uf, nomeCna }', $js);
        self::assertStringContainsString('{ numero: l.numero, uf: l.uf }', $js);
        self::assertStringContainsString("msg = j.mensagem || '';", $js);
        self::assertStringContainsString('return carregar();', $js);
        // Confirmar não depende do status (desenho: o botão está em toda linha).
        self::assertSame(1, substr_count($js, 'data-roab-confirmar="'));
        self::assertDoesNotMatchRegularExpression("/confirmada'\\s*\\?\\s*''\\s*:\\s*'<button[^']*roab-confirmar/", $js);
    }

    #[TestDox('CSS: valores do desenho para pílulas, abas, linhas alternadas e hovers')]
    public function testCssDoDesenho(): void
    {
        $css = self::arquivo('public/css/master.css');

        foreach ([
            '.mp .roab-st-conf { color: #1e7b45; background: #e9f6ee; border-color: #bfe3cc; }',
            '.mp .roab-st-div { color: #a3232b; background: #fdecee; border-color: #f3c4ca; }',
            '.mp .roab-st-nao { color: #8a6200; background: #fff6dc; border-color: #f0dba3; }',
            '.mp .roab-aba.on { border-color: #0a6fa0; background: #0a6fa0; color: #fff; }',
            '.mp .roab-linha:nth-child(even) { background: #f7f9fb; }',
            '.mp .roab-linha:hover { background: #eaf3f9; }',
            '.mp .roab-confirmar:hover { background: #17633a; }',
            '.mp .roab-reverif:hover { border-color: #0a6fa0; color: #0a6fa0; }',
            '.mp .roab-rodar:hover { background: #0b5f86; }',
            'grid-template-columns: minmax(170px,1.3fr) minmax(190px,1.4fr) 170px minmax(120px,1fr) 140px 150px 90px;',
        ] as $regra) {
            self::assertStringContainsString($regra, $css);
        }
    }

    #[TestDox('nenhum travessão nas chaves novas do catálogo nem no revisao-oab.js')]
    public function testSemTravessao(): void
    {
        $textos = [];
        $bloco = self::catalogo('master.js.revisao_oab');
        self::assertIsArray($bloco);
        array_walk_recursive($bloco, static function (mixed $v) use (&$textos): void {
            $textos[] = (string) $v;
        });
        $textos[] = (string) self::catalogo('master.js.secao.roab');

        self::assertNotEmpty($textos);
        foreach ($textos as $texto) {
            self::assertDoesNotMatchRegularExpression('/[—–]/u', $texto);
        }
        self::assertDoesNotMatchRegularExpression('/[—–]/u', self::js());
    }
}
