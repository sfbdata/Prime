<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\UseCase\CopiarDocumentosDaPastaUseCase;
use App\Pasta\UseCase\MontarZipDeDocumentosUseCase;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Lote L8-UI da aba Documentos (D5 zip, D6 copiar/colar): os contratos do `pasta-explorador.js`
 * e da barra estática do template que nenhum teste de HTML vê, porque tudo nasce de eventos.
 *
 * Teste de FOLHA (precedente: PastaExploradorInteracaoTest). Não há harness de navegador na
 * suíte; o que dá para travar é o texto das guardas, dos endpoints, dos tetos e da ordem do
 * desenho (02 - EXPEDIENTES 1.2.3, `ctxItens`/`barra`/`clip`/`colar`, L4793-4853).
 */
final class PastaExploradorZipCopiarTest extends TestCase
{
    private const JS  = __DIR__ . '/../../../public/js/pasta-explorador.js';
    private const TPL = __DIR__ . '/../../../templates/pasta/_documentos_explorador.html.twig';

    private function js(): string
    {
        return (string) file_get_contents(self::JS);
    }

    /** O corpo de `function $nome(…) {` até o fechamento no mesmo recuo de 4 espaços. */
    private function funcao(string $nome): string
    {
        $js  = $this->js();
        $ini = strpos($js, '    function ' . $nome . '(');
        self::assertNotFalse($ini, "função {$nome} não encontrada");
        $fim = strpos($js, "\n    }", $ini);

        return substr($js, $ini, $fim - $ini);
    }

    #[TestDox('zip e copiar leem urlZip/urlCopiar do #pexDados e usam o MESMO csrfLote do lote')]
    public function testConfiguracao(): void
    {
        $js = $this->js();

        self::assertStringContainsString("urlZip:              dados.urlZip || '',", $js);
        self::assertStringContainsString("urlCopiar:           dados.urlCopiar || '',", $js);
        self::assertStringNotContainsString('csrfZip', $js, 'não há token próprio: o do lote serve');
        self::assertStringNotContainsString('csrfCopiar', $js);
    }

    #[TestDox('os tetos da tela são os do servidor: 500 arquivos e 1 GB no .zip, 1 GB na cópia')]
    public function testTetosIguaisAosDoServidor(): void
    {
        $js = $this->js();

        self::assertStringContainsString('const TETO_ZIP_ARQUIVOS = ' . MontarZipDeDocumentosUseCase::TETO_DE_ARQUIVOS . ';', $js);
        self::assertSame(1024 * 1024 * 1024, MontarZipDeDocumentosUseCase::TETO_DE_BYTES);
        self::assertSame(MontarZipDeDocumentosUseCase::TETO_DE_BYTES, CopiarDocumentosDaPastaUseCase::TETO_DE_BYTES);
        self::assertStringContainsString('const TETO_BYTES        = 1024 * 1024 * 1024;   // 1 GB, no .zip e na cópia', $js);
        // A cópia tem o teto de documentos do lote (2.000), que a tela já confere em acimaDoTeto.
        self::assertSame(2000, CopiarDocumentosDaPastaUseCase::TETO_DE_DOCUMENTOS);
    }

    #[TestDox('zip: os tetos (contagem da subárvore viva e soma de `tamanho`) e o "sem arquivos" são conferidos ANTES de montar o formulário — o erro do servidor cairia como JSON cru na aba nova')]
    public function testZipPreValidaAntesDeSubmeter(): void
    {
        $zip = $this->funcao('baixarZip');

        $form = strpos($zip, "h('form'");
        self::assertNotFalse($form);
        foreach ([
            'if (acimaDoTeto(lote.documentos.length + lote.secoes.length)) return false;',
            'const lista = arquivosDoZip(lote);',
            "if (!lista.length) { toastErro('A seleção não tem arquivos para baixar.'); return false; }",
            'if (lista.length > TETO_ZIP_ARQUIVOS) {',
            "toastErro('A seleção tem ' + formatarInteiro(lista.length) + ' arquivos; o limite por .zip é ' + formatarInteiro(TETO_ZIP_ARQUIVOS) + '.');",
            'if (acimaDoTetoDeBytes(somaDeBytes(lista))) return false;',
        ] as $guarda) {
            $p = strpos($zip, $guarda);
            self::assertNotFalse($p, "guarda ausente: {$guarda}");
            self::assertLessThan($form, $p, "a guarda vem antes do formulário: {$guarda}");
        }
        // A soma é por `tamanho` (o tamanho_bytes que o servidor soma) e a mensagem é a dele.
        self::assertStringContainsString('function somaDeBytes(lista) { return lista.reduce(function (t, a) { return t + (Number(a.tamanho) || 0); }, 0); }', $this->js());
        self::assertStringContainsString("toastErro('A seleção soma ' + bytesDoTeto(bytes) + '; o limite por ação é ' + bytesDoTeto(TETO_BYTES) + '.');", $this->js());
        // Subpasta selecionada leva a subárvore inteira (como o servidor), sem repetir arquivo.
        $arvore = $this->funcao('arquivosDoZip');
        self::assertStringContainsString('descendentes(id).forEach(', $arvore);
        self::assertStringContainsString('return arquivos.filter(function (a) {', $arvore, 'filter sobre a memória: cada arquivo uma vez só');
    }

    #[TestDox('zip: POST de formulário montado por DOM (sem innerHTML), target=_blank, com _token=csrfLote, documentos[] e secoes[]; sai do DOM depois do submit')]
    public function testZipFormulario(): void
    {
        $zip = $this->funcao('baixarZip');

        self::assertStringContainsString("const formZip = h('form', { method: 'post', action: cfg.urlZip, target: '_blank', hidden: true });", $zip);
        self::assertStringContainsString("const input = h('input', { type: 'hidden', name: nome });", $zip);
        self::assertStringContainsString('input.value = String(valor);', $zip, 'valor por propriedade, não por HTML');
        self::assertStringContainsString("campo('_token', cfg.csrfLote);", $zip);
        self::assertStringContainsString("lote.documentos.forEach(function (id) { campo('documentos[]', id); });", $zip);
        self::assertStringContainsString("lote.secoes.forEach(function (id) { campo('secoes[]', id); });", $zip);
        self::assertLessThan(strpos($zip, 'formZip.submit();'), strpos($zip, 'document.body.appendChild(formZip);'));
        self::assertLessThan(strpos($zip, 'formZip.remove();'), strpos($zip, 'formZip.submit();'));
        self::assertStringNotContainsString('innerHTML', $this->js());
    }

    #[TestDox('barra (dc `barra` L4845): ordem Baixar, Copiar, Recortar, Renomear, Selecionar tudo, Excluir; Baixar troca o rótulo (arquivo direto, pasta ou vários em .zip); Copiar só com arquivo')]
    public function testBarra(): void
    {
        $tpl = (string) file_get_contents(self::TPL);
        $ini = strpos($tpl, '<div class="pex-selecao" id="pexSelecao"');
        self::assertNotFalse($ini);
        $barra = substr($tpl, $ini, strpos($tpl, '</div>', $ini) - $ini);
        preg_match_all('/data-pex-sel="([a-z]+)"/', $barra, $m);
        self::assertSame(['baixar', 'copiar', 'recortar', 'renomear', 'tudo', 'excluir'], $m[1]);
        self::assertStringContainsString('<button type="button" class="pex-selecao-acao" data-pex-sel="copiar" title="Copiar (Ctrl+C)" hidden><i class="bi bi-copy" aria-hidden="true"></i><span>Copiar</span></button>', $barra);

        $js = $this->funcao('renderizarBarra');
        self::assertStringContainsString("if (rotulo) rotulo.textContent = sel.length > 1 ? 'Baixar ' + sel.length + ' itens (.zip)' : (direto ? 'Baixar' : 'Baixar (.zip)');", $js);
        self::assertStringContainsString("baixar.title = direto ? 'Baixar o arquivo' : 'Baixar tudo que está selecionado em um arquivo .zip';", $js);
        self::assertStringContainsString('if (copiar) copiar.hidden = !sel.some(ehArquivo);', $js);
        self::assertStringContainsString("case 'copiar':   copiar(sel); break;", $this->js());
        // Um arquivo baixa direto (o link de sempre); o resto vira .zip.
        self::assertStringContainsString("if (sel.length === 1 && ehArquivo(sel[0])) { baixar(sel[0].dado); return; }\n        baixarZip(sel);", $this->funcao('baixarSelecao'));
    }

    #[TestDox('Copiar (Ctrl+C) guarda só ARQUIVOS, avisando no toast que pastas não são copiadas; só pastas não guarda nada')]
    public function testCopiarSoArquivos(): void
    {
        $copiar = $this->funcao('copiar');

        self::assertStringContainsString('const arqs = itens.filter(ehArquivo);', $copiar);
        self::assertStringContainsString("if (!arqs.length) { toast('Pastas não são copiadas: selecione arquivos.'); return; }", $copiar);
        self::assertStringContainsString("areaDeTransferencia = { op: 'copiar', chaves: arqs.map(chaveDe) };", $copiar);
        self::assertStringContainsString("toast('Copiado: ' + rotuloDe(arqs) + (arqs.length < itens.length ? ' (pastas não são copiadas)' : ''));", $copiar);
        self::assertLessThan(strpos($copiar, 'areaDeTransferencia ='), strpos($copiar, 'if (!arqs.length)'), 'só pastas: a área de transferência anterior fica');
        self::assertStringContainsString("if (ctrl && baixa === 'c') { if (sel.length) { e.preventDefault(); copiar(sel); } return; }", $this->js());
    }

    #[TestDox('Colar (menu de fundo, menu da pasta e Ctrl+V) segue o modo: recorte → mover-lote (consumido no sucesso); cópia → copiar (nunca consumida)')]
    public function testColarDistingueOModo(): void
    {
        $colar = $this->funcao('colarEm');

        $copia = strpos($colar, "if (areaDeTransferencia.op === 'copiar') { copiarLote(chaves, destinoId); return; }");
        $move  = strpos($colar, 'moverLote(chaves, destinoId).then(function (ok) { if (ok) areaDeTransferencia = null; });');
        self::assertNotFalse($copia);
        self::assertNotFalse($move);
        self::assertLessThan($move, $copia, 'a cópia desvia antes do mover-lote');
        self::assertStringNotContainsString('areaDeTransferencia = null', $this->funcao('copiarLote'), 'a cópia cola quantas vezes se quiser');
        $js = $this->js();
        self::assertStringContainsString("op('Colar', 'bi-clipboard', colarAqui, { atalho: 'Ctrl+V', desabilitado: !areaDeTransferencia })", $js);
        self::assertStringContainsString("op('Colar', 'bi-clipboard', function () { colarEm(alvo.id); }, { atalho: 'Ctrl+V', desabilitado: !areaDeTransferencia })", $js);
        self::assertStringContainsString("if (ctrl && baixa === 'v') { if (areaDeTransferencia) { e.preventDefault(); colarAqui(); } return; }", $js);
    }

    #[TestDox('copiar: JSON {_token: csrfLote, documentos, destinoId} ao urlCopiar; as linhas de `copiados` entram na memória sem recarregar, e ficam selecionadas quando coladas no nível aberto')]
    public function testCopiarInsereSemRecarregar(): void
    {
        $lote = $this->funcao('copiarLote');

        self::assertStringContainsString('if (acimaDoTeto(origem.length) || acimaDoTetoDeBytes(somaDeBytes(origem))) return Promise.resolve(false);', $lote);
        $post = strpos($lote, 'postJson(cfg.urlCopiar, { _token: cfg.csrfLote, documentos: ids, destinoId: destinoId })');
        self::assertNotFalse($post);
        self::assertLessThan($post, strpos($lote, 'acimaDoTeto('), 'os tetos vêm antes do pedido');
        self::assertStringContainsString("if (!res.ok || !res.j.ok || !Array.isArray(res.j.copiados)) throw new Error((res.j && res.j.erro) || 'Falha ao copiar.');", $lote);
        // A forma de `copiados` é a do upload/#pexDados: o mesmo inseridor.
        self::assertStringContainsString("if (inserirArquivoEnviado({ documento: d })) novos.push('arquivo:' + Number(d.id));", $lote);
        self::assertStringContainsString('renderizar();', $lote);
        self::assertStringContainsString('definirSelecao(novos, { ancora: novos[0], foco: novos[novos.length - 1] });', $lote);
        self::assertStringContainsString("toast('Colado: ' + rotulo);", $lote);
        self::assertStringContainsString("toast(rotulo + (origem.length === 1 ? ' copiado' : ' copiados') + ' para ' + nomeDoLocal(destinoId));", $lote);
        // Erro do servidor (422 original ausente / tetos) vira toast com a mensagem dele.
        self::assertStringContainsString("}).catch(function (err) { toastErro(err.message || 'Erro de comunicação.'); return false; });", $lote);
        // Reload só se a resposta vier fora da forma — e pelo caminho que já existe.
        self::assertStringNotContainsString('window.location.reload()', $lote);
        self::assertStringContainsString("if (novos.length < res.j.copiados.length) { recarregarDocumentos('Itens copiados. Atualizando a lista…'); return true; }", $lote);
    }

    #[TestDox('menus (dc `ctxItens`): vários abrem com "Baixar como .zip (N)"; pasta tem "Baixar como .zip" no lugar do Baixar e não tem Copiar; arquivo tem Copiar logo depois de Recortar')]
    public function testMenus(): void
    {
        $menu = $this->funcao('opcoesDoMenu');

        $multi = strpos($menu, 'if (multi) {');
        self::assertNotFalse($multi);
        $zipN = strpos($menu, "return [op('Baixar como .zip (' + sel.length + ')', 'bi-file-earmark-zip', function () { baixarZip(sel); })]", $multi);
        self::assertNotFalse($zipN, 'primeiro item do menu de vários');
        self::assertLessThan(strpos($menu, "op('Copiar links'", $multi), $zipN);
        self::assertStringContainsString("arqs.length ? [op('Copiar', 'bi-copy', function () { copiar(sel); }, { atalho: 'Ctrl+C' })] : []", $menu, 'vários sem arquivo: sem Copiar');
        self::assertStringContainsString(".concat(ehPasta ? [\n                op('Baixar como .zip', 'bi-download', function () { baixarZip([alvo]); }),\n            ] : [", $menu);
        self::assertStringContainsString(".concat(ehPasta ? [] : [op('Copiar', 'bi-copy', function () { copiar([alvo]); }, { atalho: 'Ctrl+C' })])", $menu);
    }
}
