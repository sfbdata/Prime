<?php

declare(strict_types=1);

namespace App\Tests\Central\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Regras do selo e do painel de presença e da inatividade no batimento (spec `presenca-e-ponto-no-cabecalho.md` §2),
 * lidas no texto dos scripts. A aparência é do smoke do dono.
 */
final class PresencaNoCabecalhoJsTest extends TestCase
{
    private static function js(string $arquivo): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../public/js/' . $arquivo);
    }

    #[TestDox('central.js: Não perturbe, depois inativo, depois o manual; a marca de atividade é um número só e o storage dela não bate')]
    public function testStatusEfetivoEInatividade(): void
    {
        $js = self::js('central.js');
        self::assertMatchesRegularExpression("/if \\(p\\.dnd && Date\\.now\\(\\) < p\\.dnd\\) \\{ return 'ocupado'; \\}\\s+var ativ = ultimaAtividade\\(\\);\\s+if \\(ativ && Date\\.now\\(\\) - ativ > INATIVO_MS\\) \\{ return 'inativo'; \\}\\s+return statusManual\\(\\);/", $js);
        self::assertStringContainsString("window.localStorage.setItem(CHAVE_ATIVIDADE, String(agora))", $js);
        self::assertStringContainsString("if (estava === 'inativo') { bater(); }", $js, 'volta da inatividade bate na hora');
        self::assertStringContainsString('ouvirAtividade(externa);', $js, 'a Central destacada também conta');
        self::assertStringNotContainsString("ev.key === CHAVE_ATIVIDADE", $js, 'o storage da marca não dispara batimento');
        self::assertStringContainsString("document.dispatchEvent(new CustomEvent('bj-central:presenca'", $js, 'no document da página: com a Central destacada a raiz muda de documento');
        self::assertStringContainsString("inativo: ['Ausente', '#f2a93b']", $js);
    }

    #[TestDox('presenca-cabecalho.js: grupos e cores do desenho, horas só suas pelo instante compartilhado, nada de innerHTML')]
    public function testPainel(): void
    {
        $js = self::js('presenca-cabecalho.js');
        // Os textos saíram para o catálogo (moldura.js.presenca.*, sistema em inglês): o JS guarda cor e ordem, e o texto vem do BJ_T.
        self::assertStringContainsString("var ST = { on: [null, '#2ecc71', 1], ausente: [null, '#f0b429', 2], ocupado: [null, '#e74c3c', 3], off: [null, '#b7c4cd', 4] };", $js);
        $catalogo = (string) file_get_contents(__DIR__ . '/../../../translations/moldura/messages+intl-icu.pt_BR.yaml');
        foreach (["'On-line'", "'Não perturbe'", "'Ausente após sem atividade'", "'sem atividade há {n} min'", "'Só você está na equipe por enquanto.'", "'Quem está on-line: {n}'", "'Ponto e horas on-line são só seus: ninguém da equipe vê. Contagem de 00:00 às 23:59, zera todo dia.'"] as $texto) {
            self::assertStringContainsString($texto, $catalogo, 'o português do painel continua o mesmo');
        }
        self::assertStringContainsString("BJ_T.t('moldura.js.presenca.sem_atividade_ha', { n: p.semAtividadeMin })", $js);
        // Fase 3 (dc L.306 e L.685): o seletor só com a URL do primário, depois da nota, com as 4 opções do desenho.
        self::assertStringContainsString("var OPCOES_AUSENCIA = [15, 30, 60, 120];", $js);
        self::assertStringContainsString("if (urlAusencia) { painel.appendChild(regraDeAusencia()); }", $js);
        self::assertStringContainsString("el('label', 'presenca-regra-rotulo', BJ_T.t('moldura.js.presenca.regra_ausencia'))", $js);
        self::assertStringContainsString("n < 60 ? BJ_T.t('moldura.js.presenca.minutos', { n: n }) : BJ_T.t('moldura.js.presenca.horas', { n: n / 60 })", $js);
        self::assertStringContainsString("if (x.eu) {   // horas: só na sua linha", $js);
        self::assertStringContainsString("var dt = agora - Math.max(hr.l || agora, meiaNoite.getTime());", $js, 'o último instante é o compartilhado');
        self::assertStringContainsString('HORAS_INTERVALO_MAX_MS = 90000', $js);
        self::assertStringContainsString("chaveDeStatus(m.id) !== 'off'", $js, 'entrou/saiu compara quem não está desligado');
        self::assertStringContainsString('function bipeUmaVez(assinatura, sobe)', $js);
        self::assertStringContainsString("BJ_T.t('moldura.js.presenca.nota')", $js);
        self::assertStringContainsString("BJ_T.t('moldura.js.presenca.so_voce')", $js);
        self::assertStringContainsString("if (id === euId) { return 'on'; }", $js, 'dc L.680: você aparece sempre On-line');
        self::assertStringContainsString("return ST[a.k][2] - ST[b.k][2] || (b.eu - a.eu)", $js, 'grupos na ordem do dc, você primeiro');
        self::assertStringContainsString("var ligados = pessoas().filter(function (x) { return x.k !== 'off'; });", $js, 'N do selo: quem não está desligado');
        self::assertStringContainsString("var nOn = lista.filter(function (x) { return x.k === 'on'; }).length;", $js, 'N do resumo: só o grupo On-line');
        self::assertStringContainsString("if (ultimo.a === assinatura && Date.now() - (ultimo.t || 0) < 30000) { return; }", $js, 'bipe uma vez entre abas');
        self::assertStringContainsString("if (!equipe) { return; }   // sem a equipe", $js, 'falha na equipe não gera entrou falso');
        self::assertStringContainsString("if (equipe && lista.length <= 1)", $js);
        self::assertStringContainsString("selo.setAttribute('aria-label', BJ_T.t('moldura.js.presenca.quem_online_n', { n: n }));", $js);
        self::assertStringContainsString("b.setAttribute('aria-label', BJ_T.t('moldura.js.presenca.fechar'));", $js, 'o X do protótipo (bluejus-central.js)');
        self::assertStringContainsString("BJ_T.t('moldura.js.presenca.visto_ultimo', { quando: hmDe(m.vistoEm) }) : BJ_T.t('moldura.js.presenca.sem_atividade_recente')", $js, 'det do desligado igual ao da Central');
        $css = (string) file_get_contents(__DIR__ . '/../../../public/css/presenca-cabecalho.css');
        self::assertStringContainsString('line-height: normal;', $css);
        self::assertStringContainsString('.presenca-cab { position: absolute; right: 3px; bottom: 0;', $css, 'selo a -5/-4 do avatar (dc L.67)');
        self::assertStringNotContainsString('innerHTML', $js);
        self::assertDoesNotMatchRegularExpression('/[–—]/u', $js);
    }

    #[TestDox('presenca-cabecalho.js: a lista do painel mostra a foto do perfil (a URL que vem da equipe) e as iniciais só sem foto; o ponto de status fica sobre o avatar')]
    public function testFotoNoPainel(): void
    {
        $js = self::js('presenca-cabecalho.js');
        self::assertStringContainsString("foto: m.foto || null", $js, 'a equipe guardada leva a foto');
        self::assertStringContainsString("var CHAVE_EQUIPE = 'presenca-equipe:v2:' + chave;", $js, 'a cópia antiga da sessão, sem foto, não é reaproveitada');
        self::assertMatchesRegularExpression("/function avatar\\(m\\) \\{\\s+var av = el\\('span', 'presenca-av', m\\.foto \\? null : iniciais\\(m\\.nome\\)\\);\\s+if \\(m\\.foto\\) \\{\\s+var img = el\\('img'\\);\\s+img\\.src = m\\.foto;/", $js);
        self::assertMatchesRegularExpression("/var av = avatar\\(x\\.m\\);\\s+var pt = el\\('span', 'presenca-av-ponto'\\);[\\s\\S]*?av\\.appendChild\\(pt\\);/", $js, 'o ponto entra depois da foto, por cima');
        self::assertStringContainsString('.presenca-av img {', (string) file_get_contents(__DIR__ . '/../../../public/css/presenca-cabecalho.css'));
    }
}
