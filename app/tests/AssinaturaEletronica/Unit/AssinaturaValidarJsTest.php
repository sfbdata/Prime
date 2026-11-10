<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Aba Validar e leitura do QR Code (etapa 3, bloco 2; inventário A7), lidas no `public/js/assinatura-validar.js` como
 * FOLHA: não há navegador nem câmera no PHPUnit. O que se prova são os valores do desenho (dc:275, dc:338-343, dc:380,
 * dc:457): a câmera de trás, o ciclo de 180 ms, o BarcodeDetector antes do jsQR em 640 px, as larguras da foto, o
 * padrão do código, o som de dois tons, a vibração e que todo texto sai do catálogo. E o que a revisão do bloco pediu:
 * a janela fora das abas com o foco preso nela, a câmera que para quando a janela some, o pedido velho de permissão que
 * para o próprio fluxo, o escape do resultado e o código no #hash da tela (dc:299). Ver a câmera funcionando é smoke
 * do dono.
 */
#[CoversNothing]
final class AssinaturaValidarJsTest extends TestCase
{
    private const APP = __DIR__ . '/../../..';

    private static function js(): string
    {
        return (string) file_get_contents(self::APP . '/public/js/assinatura-validar.js');
    }

    /** @param list<string> $pedacos */
    private static function emOrdem(string $codigo, array $pedacos, string $onde): void
    {
        $de = 0;
        foreach ($pedacos as $pedaco) {
            $achou = strpos($codigo, $pedaco, $de);
            self::assertNotFalse($achou, $onde . ': falta, nesta ordem, ' . $pedaco);
            $de = $achou + \strlen($pedaco);
        }
    }

    #[TestDox('câmera: a de trás, ciclo de 180 ms, BarcodeDetector primeiro e, sem ele, o jsQR numa cópia de 640 px')]
    public function testCamera(): void
    {
        $js = self::js();

        self::assertStringContainsString("getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false })", $js);
        self::assertStringContainsString('const CICLO_MS = 180;', $js);
        self::assertStringContainsString('const LARGURA_CAMERA = 640;', $js);
        self::assertStringContainsString("new window.BarcodeDetector({ formats: ['qr_code'] })", $js);
        self::emOrdem($js, [
            'if (bd) {',
            'await bd.detect(video)',
            '} else if (window.jsQR) {',
            'Math.round(w * video.videoHeight / video.videoWidth)',
            'window.jsQR(g.getImageData(0, 0, w, h).data, w, h)',
            "msg.textContent = T('cam_outro')",
            'setTimeout(roda, CICLO_MS)',
        ], 'ciclo da câmera');
        self::emOrdem($js, ["msg.textContent = T('cam_procurando')", "msg.textContent = T('cam_indisponivel')"], 'mensagens da câmera');
    }

    #[TestDox('foto: o jsQR nas larguras 1600, 2400 e 900 e os dois avisos em vermelho')]
    public function testFoto(): void
    {
        $js = self::js();

        self::assertStringContainsString('const LARGURAS_FOTO = [1600, 2400, 900];', $js);
        self::emOrdem($js, [
            'for (const lim of LARGURAS_FOTO)',
            'Math.min(1, lim / Math.max(i.width, i.height))',
            "avisar(T('foto_sem_qr'))",
            "avisar(T('foto_ilegivel'))",
        ], 'foto');
        self::assertStringContainsString('<div class="bjaToast is-erro" role="status">', $js, 'o aviso vermelho (#a3232b) do desenho');
        self::assertStringContainsString('const AVISO_MS = 3400;', $js);
    }

    #[TestDox('código achado: o padrão do desenho, para a câmera, valida, toca 1568 e 2093 Hz e vibra 40 ms')]
    public function testAoAchar(): void
    {
        $js = self::js();

        self::assertStringContainsString('const RE_CODIGO = /BJA-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}/i;', $js);
        self::assertStringContainsString('m[0].toUpperCase()', $js);
        self::emOrdem($js, ['function achou(c) {', 'fecharCam();', 'campo.value = c;', 'validar();', 'som();', 'navigator.vibrate(40)'], 'achouQr');
        self::emOrdem($js, ['tom(1568, 0, .55, .26);', 'tom(2093, .1, .85, .3);'], 'som de dois tons');
    }

    #[TestDox('validar: aparado e em maiúsculas, à consulta pública; vazio é "não localizado"; digitar apaga o resultado')]
    public function testValidar(): void
    {
        $js = self::js();

        self::emOrdem($js, [
            'const c = campo.value.trim().toUpperCase()',
            'if (!c) { mostrar(null); return; }',
            "fetch(consulta + '?codigo=' + encodeURIComponent(c)",
            'if (r.ok || r.status === 404) { mostrar(j && j.documento ? j.documento : null); return; }',
        ], 'validar');
        self::assertStringContainsString("campo.addEventListener('input', () => { seq++; res.innerHTML = ''; });", $js);
        self::emOrdem($js, [
            "'<div class=\"bjaValErro\"><b>' + esc(T('nao_localizado')) + '</b> ' + esc(T('confira')) + '</div>'",
            "'<div class=\"bjaValOk\"><b>' + esc(T('localizado', { nome: d.nome })) + '</b><br>'",
            "esc(T('situacao', { situacao: d.situacao })) + '<br>'",
            "esc(T('hash')) + ' <code>' + esc(d.hash || '') + '</code>'",
            "'<div>' + esc(linhaDoSignatario(s)) + '</div>'",
        ], 'resultado');
    }

    #[TestDox('resultado escapado: o esc troca & < > " e \' por entidade, e todo valor do servidor passa por ele')]
    public function testEscapeDoResultado(): void
    {
        $js = self::js();

        self::assertStringContainsString("const esc = t => String(t == null ? '' : t).replace(/[&<>\\x22\\x27]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '\\x22': '&quot;', '\\x27': '&#39;' }[c]));", $js);
        preg_match('/function htmlDoResultado\(d\) \{(.*?)\n    \}/s', $js, $m);
        self::assertNotEmpty($m[1] ?? '', 'htmlDoResultado no arquivo');
        self::assertDoesNotMatchRegularExpression('/\+ d\.|\+ s\./', $m[1] ?? '', 'nenhum campo do documento entra sem esc');
        self::assertStringContainsString('<div class="bjaToast is-erro" role="status">\' + esc(texto)', $js, 'o aviso (o erro do servidor) também');
    }

    #[TestDox('janela da câmera fora das abas (dc:275): achada pelo aria-controls, ligada à parte, foco entra e fica preso')]
    public function testJanelaForaDasAbasComFocoPreso(): void
    {
        $js = self::js();

        self::assertStringContainsString("const janela = botaoCam ? document.getElementById(botaoCam.getAttribute('aria-controls') || '') : null;", $js);
        self::assertStringContainsString("[raiz, janela].forEach(n => { n.addEventListener('click', aoClicar); n.addEventListener('change', aoEscolherFoto); });", $js);
        self::emOrdem($js, ['async function abrirCam() {', 'const meu = ++pedido;', 'if (janela.hidden) { origem = document.activeElement; }', 'janela.hidden = false;', 'focarNaJanela(false);'], 'o foco entra ao abrir');
        self::emOrdem($js, [
            "document.addEventListener('focusin', ev => {",
            'if (janela.hidden || janela.contains(ev.target) || !(ev.target instanceof Node)) { return; }',
            'focarNaJanela(!!(janela.compareDocumentPosition(ev.target) & Node.DOCUMENT_POSITION_PRECEDING));',
        ], 'o foco que escapa volta (Shift+Tab não chega às abas)');
        self::emOrdem($js, ['function fecharCam() {', 'pedido++;', 'pararCam();', 'janela.hidden = true;', 'origem.focus();'], 'fechar devolve o foco');
    }

    #[TestDox('a câmera para sempre que a janela deixa de estar visível, a cada ciclo de 180 ms')]
    public function testCameraParaQuandoAJanelaSome(): void
    {
        $js = self::js();

        self::assertStringContainsString('const aberta = () => !janela.hidden && janela.isConnected && janela.getClientRects().length > 0;', $js);
        self::emOrdem($js, ['const roda = async () => {', 'if (camId !== id) { return; }', 'if (!aberta()) { fecharCam(); return; }', 'setTimeout(roda, CICLO_MS)'], 'ciclo da câmera');
        self::emOrdem($js, ['function pararCam() {', 'camId = 0;', 'fluxo.getTracks().forEach(t => t.stop()); fluxo = null;', 'video.srcObject = null;'], 'parar apaga a luz');
    }

    #[TestDox('dois abrirCam seguidos: o pedido velho de permissão dá stop no próprio fluxo e não assume a janela')]
    public function testPedidoVelhoDaCamera(): void
    {
        self::emOrdem(self::js(), [
            'const meu = ++pedido;',
            "await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });",
            'if (meu !== pedido || !aberta()) {',
            'st.getTracks().forEach(t => t.stop());',
            'if (meu === pedido) { fecharCam(); }',
            'return;',
            'fluxo = st;',
            'video.srcObject = st;',
            'lerCamera(meu);',
            "if (meu === pedido && aberta()) { msg.textContent = T('cam_indisponivel'); }",
        ], 'abrirCam marcado');
    }

    #[TestDox('código BJA no #hash (dc:299): a tela abre na aba Validar com o código no campo e valida')]
    public function testCodigoNoHash(): void
    {
        $tela = (string) file_get_contents(self::APP . '/public/js/assinatura.js');
        $js = self::js();

        self::emOrdem($tela, [
            '/* ── Início',
            "const codHash = el.abaVal && window.BJAValidar ? window.BJAValidar.codQr(location.hash) : '';",
            "if (codHash) { s.aba = 'val'; }",
            'mostrarTela();',
            'desenharLista();',
            "if (codHash) { window.BJAValidar.validarCodigo(el.abaVal.querySelector('[data-bja-validar]'), codHash); }",
            'carregar();',
        ], 'início da tela');
        self::emOrdem($tela, ['function desenharLista() {', "if (el.abaVal) { el.abaVal.hidden = s.aba !== 'val'; }", "if (s.aba === 'val') { return; }"], 'a aba Validar aparece');
        self::assertStringContainsString('Ficam para depois: Baixar PDF com selo e Vincular à pasta.', $tela, 'o código BJA e a aba Validar já não ficam para depois');

        self::assertStringContainsString('raiz.bjaValidar = { validarCodigo: preencherEValidar };', $js);
        self::assertStringContainsString('function preencherEValidar(c) { campo.value = c; validar(); }', $js);
        self::emOrdem($js, ['function validarCodigo(raiz, c) {', 'montar(raiz);', 'raiz.bjaValidar.validarCodigo(c);'], 'validarCodigo');
        self::assertStringContainsString('window.BJAValidar = { montar: montar, codQr: codQr, validarCodigo: validarCodigo };', $js);
    }

    #[TestDox('cartão do código no detalhe (A6, dc:221): o código do documento ou "Gerado no envio", bold 17px monospace #a3232b')]
    public function testCartaoDoCodigoNoDetalhe(): void
    {
        $tela = (string) file_get_contents(self::APP . '/public/js/assinatura.js');
        $css = (string) file_get_contents(self::APP . '/public/css/assinatura.css');

        self::assertStringContainsString(
            "'<section class=\"bjaCard bjaCodCard\"><div class=\"bjaCodRot\">' + esc(T('det.codigo')) + '</div><div class=\"bjaCodVal\">' + esc(d.codigo || T('det.gerado_no_envio')) + '</div>'",
            $tela,
        );
        self::assertStringContainsString('.bja .bjaCodCard { padding: 12px 14px; display: flex; flex-direction: column; gap: 8px; }', $css);
        self::assertStringContainsString('.bja .bjaCodRot { font-size: 12px; font-weight: bold; color: var(--bja-t3); }', $css);
        self::assertStringContainsString('.bja .bjaCodVal { font: bold 17px monospace; color: #a3232b; letter-spacing: 1px; }', $css);
        $det = Yaml::parseFile(self::APP . '/translations/assinatura_eletronica_tela/messages+intl-icu.pt_BR.yaml')['assinatura_eletronica_tela']['js']['det'];
        self::assertSame('CÓDIGO DE VALIDAÇÃO', $det['codigo']);
        self::assertSame('Gerado no envio', $det['gerado_no_envio']);
    }

    #[TestDox('todo T() do arquivo tem texto no bloco js.validar do catálogo da tela')]
    public function testTextosNoCatalogo(): void
    {
        $catalogo = Yaml::parseFile(self::APP . '/translations/assinatura_eletronica_tela/messages+intl-icu.pt_BR.yaml')['assinatura_eletronica_tela']['js']['validar'];
        preg_match_all("/\\bT\\('([a-z_]+)'/", self::js(), $m);

        self::assertNotEmpty($m[1]);
        foreach (array_unique($m[1]) as $chave) {
            self::assertArrayHasKey($chave, $catalogo, $chave);
        }
    }
}
