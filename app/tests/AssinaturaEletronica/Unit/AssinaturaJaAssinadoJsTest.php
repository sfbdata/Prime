<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * O documento que já chega assinado (etapa 5, bloco 5A) na tela interna, pela conferência ✓/✗ com o desenho 17 de
 * 08/11/2026 (dc:188, 218, 381, 383, 384, 462 e 466), lido no `public/js/assinatura-ja-assinado.js` e na ligação do
 * `public/js/assinatura.js` como texto: não há navegador no PHPUnit. Ver o quadro e os botões funcionando é smoke do
 * dono.
 */
#[CoversNothing]
final class AssinaturaJaAssinadoJsTest extends TestCase
{
    private const APP = __DIR__ . '/../../..';

    private static function modulo(): string
    {
        return (string) file_get_contents(self::APP . '/public/js/assinatura-ja-assinado.js');
    }

    private static function tela(): string
    {
        return (string) file_get_contents(self::APP . '/public/js/assinatura.js');
    }

    /** Corpo de uma função do primeiro nível (4 espaços de recuo), até a chave que a fecha. */
    private static function funcao(string $js, string $nome): string
    {
        $inicio = strpos($js, 'function ' . $nome . '(');
        self::assertNotFalse($inicio, 'função ' . $nome . ' não encontrada');
        $fim = strpos($js, "\n    }\n", $inicio);
        self::assertNotFalse($fim, 'fim da função ' . $nome);

        return substr($js, $inicio, $fim - $inicio);
    }

    #[TestDox('quadro do passo 3 (dc:188): caixa, título com bi-patch-check-fill na cor, linhas e nota com os estilos do desenho')]
    public function testQuadroDoPasso3(): void
    {
        $js = self::modulo();

        self::assertStringContainsString('style="border:1px solid #bcd6e5; border-left:4px solid \' + esc(q.cor) + \'; background:#f5fafd; padding:12px 14px; display:flex; flex-direction:column; gap:8px; margin-bottom:10px;"', $js);
        self::assertStringContainsString('<b style="font-size:14.5px; color:#0b3550;"><i class="bi bi-patch-check-fill" style="color:\' + esc(q.cor) + \';"></i> \' + esc(q.titulo) + \'</b>', $js);
        self::assertStringContainsString('<div style="display:flex; gap:9px; align-items:flex-start; font-size:13px;"><i class="bi \' + esc(l.icone) + \'" style="color:\' + esc(l.cor) + \'; font-size:15px;"></i>', $js);
        self::assertStringContainsString('<div style="flex:1 1 auto; min-width:0;"><b>\' + esc(l.nome) + \'</b> <span style="color:#5b7383;">\' + esc(l.documento || \'\') + \'</span>', $js);
        self::assertStringContainsString('<div style="color:#34505f; line-height:1.4;">\' + esc(det) + \'</div>', $js);
        self::assertStringContainsString('<div style="font-size:12.5px; color:#34505f; line-height:1.45;">\' + esc(q.nota) + \'</div>', $js);
        self::assertStringContainsString("return q && (q.linhas || []).length ? caixa(q, miolo(q)) : '';", self::funcao($js, 'quadro'));
    }

    #[TestDox('linha: data no fuso do navegador ou "data não informada", depois o detalhe do servidor, separados por " · " (fm do desenho)')]
    public function testDataDaLinha(): void
    {
        self::assertStringContainsString("const det = [dataHora(l.em) || J('data_nao_informada'), l.detalhe].filter(Boolean).join(' · ');", self::funcao(self::modulo(), 'miolo'));
    }

    #[TestDox('quadro do detalhe (dc:218): botões do desenho, carimbo só com dPrCar e URL, link do ITI e aviso em #8a6200')]
    public function testQuadroDoDetalhe(): void
    {
        $detalhe = self::funcao(self::modulo(), 'quadroDoDetalhe');

        self::assertStringContainsString('<div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">', $detalhe);
        self::assertStringContainsString('<button type="button" class="bjaB" data-bja-acao="ja-original"><i class="bi bi-shield-lock"></i>\' + esc(J(\'baixar_original\')) + \'</button>', $detalhe);
        self::assertStringContainsString('const carimbo = !!(q.carimbo && urlCarimbo);', $detalhe);
        self::assertStringContainsString('(carimbo ? \'<button type="button" class="bjaB" data-bja-acao="ja-carimbo"><i class="bi bi-stamp"></i>\' + esc(J(\'copia_carimbo\')) + \'</button>\' : \'\')', $detalhe);
        self::assertStringContainsString('<a class="bjaB" href="\' + ITI + \'" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i>\' + esc(J(\'conferir_iti\')) + \'</a></div>', $detalhe);
        self::assertStringContainsString("const ITI = 'https://validar.iti.gov.br';", self::modulo());
        self::assertStringContainsString('<div style="font-size:12.5px; color:#8a6200; line-height:1.45;">\' + esc(q.aviso) + \'</div>', $detalhe);
        self::assertStringContainsString('return caixa(q, miolo(q) + botoes + aviso);', $detalhe);
    }

    #[TestDox('rótulo do Baixar: "Baixar manifesto com selo" com o documento já assinado (dc:462)')]
    public function testRotuloDoBaixar(): void
    {
        $rotulo = self::funcao(self::modulo(), 'rotuloDoBaixar');

        self::assertStringContainsString("if (d && d.jaAssinado) { return T('pdf.baixar_manifesto'); }", $rotulo);
        self::assertStringContainsString("return d && d.folhaBranca ? T('pdf.baixar_rotulo') : T('pdf.baixar');", $rotulo);
    }

    #[TestDox('conferência e downloads pelo pedir() do assinatura.js, sem fetch solto; o original sai como "{nome} - original.pdf"')]
    public function testPedirEOriginal(): void
    {
        $js = self::modulo();

        self::assertDoesNotMatchRegularExpression('/\bfetch\s*\(/', $js);
        self::assertStringContainsString("fd.append('arquivo', arquivo, arquivo.name);", self::funcao($js, 'conferir'));
        self::assertStringContainsString("j = await pedir(endereco, { method: 'POST', body: fd });", self::funcao($js, 'conferir'));
        $original = self::funcao($js, 'baixarOriginal');
        self::assertStringContainsString("salvar(await pedir(q.original, { blob: true }), J('nome_original', { nome: d.nome }));", $original);
        self::assertStringContainsString("avisar(e && e.status === 404 ? J('original_nao_encontrado') : (e && e.texto) || T('erro_geral'), 'erro');", $original);
        self::assertStringContainsString('setTimeout(() => URL.revokeObjectURL(u), 4000);', self::funcao($js, 'salvar'));
    }

    #[TestDox('passo 1: 422 (e 403 com erro) mostra o texto do servidor; 429, 5xx, rede caída ou 403 sem erro, o "tente de novo" (não o p1.erro)')]
    public function testFalhaDaConferencia(): void
    {
        $js = self::modulo();
        $conferir = self::funcao($js, 'conferir');

        self::assertStringContainsString("const falha = new Error('conferência do PDF');", $conferir);
        self::assertStringContainsString('falha.status = e && e.status;', $conferir);
        self::assertStringContainsString('falha.texto = textoDaFalha(e);', $conferir);
        self::assertStringContainsString('throw falha;', $conferir);
        $texto = self::funcao($js, 'textoDaFalha');
        self::assertStringContainsString("const texto = e && e.texto ? String(e.texto) : '';", $texto);
        self::assertStringContainsString("return texto && (e.status === 422 || e.status === 403) ? texto : J('conferir_indisponivel');", $texto);
        self::assertStringContainsString("arqSt((e && e.texto) || T('p1.erro'));", (string) file_get_contents(self::APP . '/public/js/assinatura.js'), 'o subir do passo 1 mostra o texto da falha');
        self::assertSame('Não foi possível conferir o arquivo agora. Tente de novo em instantes.', $this->catalogo()['ja_assinado']['conferir_indisponivel']);
    }

    #[TestDox('carimbo (contrato do 5B): 200 baixa; 422 motivo + " Use o original."; 422 semAssinatura e 404 só o texto; o resto, o texto geral; tudo no aviso vermelho')]
    public function testCarimboNosCincoCasos(): void
    {
        $js = self::modulo();
        $baixar = self::funcao($js, 'baixarCarimbo');
        $aviso = self::funcao($js, 'avisoDoCarimbo');

        self::assertStringContainsString("salvar(await pedir(endereco, { blob: true }), J('nome_carimbo', { nome: d.nome }));", $baixar, '200');
        self::assertStringContainsString("avisar(avisoDoCarimbo(e), 'erro');", $baixar, 'aviso vermelho (#a3232b)');
        self::assertStringContainsString("if (!texto || (e.status !== 422 && e.status !== 404)) { return J('carimbo.falhou'); }", $aviso, 'qualquer outra falha');
        self::assertStringContainsString("return e.status === 422 && !(e.dados && e.dados.semAssinatura) ? texto + J('carimbo.use_original') : texto;", $aviso, '422, 422 semAssinatura e 404');

        $pedir = self::funcao(self::tela(), 'pedir');
        self::assertStringContainsString('if (o.blob && r.ok) { return r.blob(); }', $pedir);
        self::assertStringContainsString("erro.texto = (j && j.erro) || ''; erro.dados = j;", $pedir, 'o erro leva o JSON (semAssinatura)');

        $catalogo = $this->catalogo()['ja_assinado'];
        self::assertSame(' Use o original.', $catalogo['carimbo']['use_original']);
        self::assertSame('Não foi possível carimbar. Use o original.', $catalogo['carimbo']['falhou']);
        self::assertSame('{nome} - com carimbo.pdf', $catalogo['nome_carimbo']);
        self::assertSame('{nome} - original.pdf', $catalogo['nome_original']);
        self::assertStringContainsString('.bja .bjaToast.is-erro { background: #a3232b; }', (string) file_get_contents(self::APP . '/public/css/assinatura.css'));
    }

    #[TestDox('ligação no assinatura.js: módulo opcional, URLs da raiz, w.pres, quadro no passo 3 e no detalhe, ações do detalhe')]
    public function testLigacao(): void
    {
        $tela = self::tela();

        self::assertStringContainsString('const J = window.BJAJaAssinado || null;', $tela);
        self::assertStringContainsString("pdfConferir: ds.urlPdfConferir || '',", $tela);
        self::assertStringContainsString("carimbo: ds.urlCarimbo || '',", $tela);
        self::assertStringContainsString('pres: null,', self::funcao($tela, 'novoW'));
        self::assertStringContainsString("return '<div class=\"bjaP3\">' + (J ? J.quadro(w.pres) : '') + '<div class=\"bjaP3Cab\">", self::funcao($tela, 'passo3'));
        $subir = self::funcao($tela, 'subir');
        self::assertStringContainsString('const pres = arquivo && J && url.pdfConferir ? await J.conferir(url.pdfConferir, arquivo, pedir) : null;', $subir);
        self::assertStringContainsString('sign: [sigVazio()], pres: pres });', $subir);
        self::assertStringContainsString("arqSt((e && e.texto) || T('p1.erro'));", $subir);
        self::assertStringContainsString("'</span></div>'\n            + (J ? J.quadroDoDetalhe(d, url.carimbo) : '')\n            + '<div class=\"bjaGrid\">", self::funcao($tela, 'desenharDetalhe'), 'entre o topo e a grade, como dc:218');
        $acao = self::funcao($tela, 'acao');
        self::assertStringContainsString("case 'ja-original': if (J && s.det) { J.baixarOriginal(s.det, pedir, avisar); } break;", $acao);
        self::assertStringContainsString("case 'ja-carimbo': if (J && s.det && url.carimbo) { J.baixarCarimbo(s.det, comId(url.carimbo, s.det.id), pedir, avisar); } break;", $acao);
        self::assertStringNotContainsString('pres', self::funcao($tela, 'retomar'), 'o retomar do desenho não leva o pres');
    }

    #[TestDox('toda chave do catálogo usada no módulo existe, e o módulo não tem travessão')]
    public function testChavesETravessao(): void
    {
        $js = self::modulo();
        $catalogo = $this->catalogo();
        // Só a chave inteira entre aspas (o atalho J monta 'ja_assinado.' + chave e fica de fora).
        preg_match_all("/\\bJ\\('([a-z_.]+)'\\s*[,)]/", $js, $doBloco);
        preg_match_all("/\\bT\\('([a-z_.]+)'\\s*[,)]/", $js, $daTela);
        self::assertNotEmpty($doBloco[1]);

        foreach (array_unique($doBloco[1]) as $chave) {
            self::assertIsString(self::valor($catalogo, 'ja_assinado.' . $chave), 'ja_assinado.' . $chave);
        }
        foreach (array_unique($daTela[1]) as $chave) {
            self::assertIsString(self::valor($catalogo, $chave), $chave);
        }
        self::assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', $js);
    }

    /** @return array<string, mixed> o bloco `assinatura_eletronica_tela.js` */
    private function catalogo(): array
    {
        return Yaml::parseFile(self::APP . '/translations/assinatura_eletronica_tela/messages+intl-icu.pt_BR.yaml')['assinatura_eletronica_tela']['js'];
    }

    /** @param array<string, mixed> $catalogo */
    private static function valor(array $catalogo, string $chave): mixed
    {
        $atual = $catalogo;
        foreach (explode('.', $chave) as $parte) {
            if (!\is_array($atual) || !\array_key_exists($parte, $atual)) {
                return null;
            }
            $atual = $atual[$parte];
        }

        return $atual;
    }
}
