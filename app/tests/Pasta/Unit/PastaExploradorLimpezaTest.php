<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Lote L9 da aba Documentos: duplicados e limpeza na tela (DOC-62..66; desenho 02 - EXPEDIENTES
 * 1.2.3, `expLimpeza` L4686-4697, selos L2251/L4746-4747, faixa L2217-2235 e L4945-4952).
 *
 * Teste de FOLHA (precedente: PastaExploradorFavoritosTest): sem harness de navegador, trava o
 * texto do contrato — rótulo honesto (regras, não IA: S-4), nada de innerHTML, a chave nova de
 * sessionStorage, o Revisar indo pelo excluir-lote com confirm(), e os tokens do desenho.
 */
final class PastaExploradorLimpezaTest extends TestCase
{
    private const JS   = __DIR__ . '/../../../public/js/pasta-explorador.js';
    private const CSS  = __DIR__ . '/../../../public/css/pasta-explorador.css';
    private const TWIG = __DIR__ . '/../../../templates/pasta/_documentos_explorador.html.twig';

    private function js(): string
    {
        return (string) file_get_contents(self::JS);
    }

    private function css(): string
    {
        return (string) file_get_contents(self::CSS);
    }

    private function twig(): string
    {
        return (string) file_get_contents(self::TWIG);
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

    /** O trecho do JS do L9 (do cabeçalho da seção até a seção seguinte). */
    private function secaoDoL9(): string
    {
        $js  = $this->js();
        $ini = strpos($js, '// ------------------------------------------- duplicados e limpeza (L9) ---');
        self::assertNotFalse($ini);
        $fim = strpos($js, '// ----------------------------------------------------------- seleção ----', $ini);
        self::assertNotFalse($fim);

        return substr($js, $ini, $fim - $ini);
    }

    #[TestDox('rótulo honesto (S-4): "Sugestões de limpeza — por regras", sem bi-stars, sem ✦, sem "IA" — nem no HTML da faixa nem no JS')]
    public function testRotuloHonesto(): void
    {
        $twig = $this->twig();
        $ini  = strpos($twig, '<div class="pex-limpeza" id="pexLimpeza"');
        self::assertNotFalse($ini);
        $faixa = substr($twig, $ini, strpos($twig, '{# A lista é o alvo do teclado', $ini) - $ini);

        self::assertStringContainsString('Sugestões de limpeza — por regras', $faixa);
        self::assertStringContainsString('aria-label="Sugestões de limpeza por regras"', $faixa);
        self::assertStringContainsString('Não é inteligência artificial.', $faixa, 'o título explica que são regras');
        self::assertStringNotContainsString('bi-stars', $faixa);
        self::assertStringNotContainsString('✦', $faixa);
        self::assertDoesNotMatchRegularExpression('/\bI\.?A\b/u', $faixa);
        self::assertStringContainsString(' hidden>', substr($faixa, 0, strpos($faixa, "\n")), 'a faixa nasce oculta');

        $l9 = $this->secaoDoL9();
        self::assertStringNotContainsString('bi-stars', $l9);
        self::assertStringNotContainsString('✦', $l9);
        self::assertStringNotContainsString('bi-stars', $this->js(), 'nenhuma estrela de "IA" no explorador');
        self::assertStringContainsString("'Ganhe espaço: ' + partes.join(', ') + ' · libera cerca de ' + formatarBytes(somaDaLimpeza(sug))", $l9, 'texto do desenho (dc L4946)');
    }

    #[TestDox('nada de innerHTML/insertAdjacentHTML: nome de arquivo é dado do usuário — selos, motivos e itens vão por h()/textContent')]
    public function testSemInnerHtml(): void
    {
        $js = $this->js();
        self::assertStringNotContainsString('innerHTML', $js);
        self::assertStringNotContainsString('insertAdjacentHTML', $js);
        self::assertStringNotContainsString('outerHTML', $js);

        self::assertStringContainsString("h('span', { class: 'pex-selo pex-selo--identico', title: titulo }, [icone('bi-files'), 'Idêntico'])", $this->funcao('seloIdentico'));
        self::assertStringContainsString("h('span', { class: 'pex-limpeza-item-nome', text: x.a.nome, title: x.a.nome })", $this->funcao('renderizarLimpeza'));
        self::assertStringContainsString('el.limpezaTexto.textContent = ', $this->funcao('renderizarLimpeza'));
    }

    #[TestDox('dispensar: sessionStorage com a chave pex:limpeza:<pastaId>, gravada e lida dentro de try/catch')]
    public function testDispensarNaSessao(): void
    {
        $js = $this->js();
        self::assertStringContainsString("const CHAVE_LIMPEZA     = 'pex:limpeza:' + pastaId;", $js);
        self::assertStringContainsString("try { return sessionStorage.getItem(CHAVE_LIMPEZA) === '1'; } catch (e) { return false; }", $js);
        self::assertStringContainsString("try { sessionStorage.setItem(CHAVE_LIMPEZA, '1'); } catch (e) { /* silencioso */ }", $js);

        // Toda escrita de sessionStorage é da pasta aberta ou da faixa dispensada — mais nada.
        preg_match_all("/sessionStorage\.setItem\(([^,]+),/", $js, $m);
        self::assertSame(['CHAVE_CAMINHO', 'CHAVE_LIMPEZA'], array_values(array_unique($m[1])));
        foreach (preg_grep('/sessionStorage\./', explode("\n", $js)) ?: [] as $linha) {
            if (str_contains($linha, 'sessionStorage (')) {
                continue; // comentário do cabeçalho
            }
            self::assertMatchesRegularExpression('/try \{ .*sessionStorage\.(getItem|setItem)\(.*\} catch \(e\)/', $linha);
        }

        $dispensar = $this->secaoDoL9();
        self::assertMatchesRegularExpression('/el\.limpezaDispensar\.addEventListener\(\'click\', function \(\) \{\s*limpezaDispensada = true;\s*gravarLimpezaDispensada\(\);/', $dispensar);
    }

    #[TestDox('Revisar → caixas de marcar (role=checkbox, todas marcadas ao abrir) → Excluir vai pelo excluirItens (excluir-lote + confirm), sem rota própria')]
    public function testRevisarUsaExcluirLote(): void
    {
        $l9 = $this->secaoDoL9();
        self::assertStringContainsString("role: 'checkbox', 'aria-checked': on ? 'true' : 'false'", $l9);
        self::assertStringContainsString("limpezaAberta = !limpezaAberta;\n            limpezaDesmarcados = new Set();", $l9, 'ao abrir, tudo marcado (dc `marc` = todos)');
        self::assertStringContainsString("excluirItens(itens, function () { limpezaAberta = false; limpezaDesmarcados = new Set(); });", $l9);
        self::assertStringContainsString("return { tipo: 'arquivo', id: x.a.id, nome: x.a.nome, dado: x.a };", $l9, 'só arquivos — nunca uma pasta');
        self::assertStringNotContainsString('fetch(', $l9, 'nenhum pedido próprio: o lote é o excluir-lote');
        self::assertStringNotContainsString('postJson(', $l9);
        self::assertStringNotContainsString('confirm(', $l9, 'o confirm é o do excluirItens');
        self::assertStringContainsString("'Excluir ' + marcados.length + ' selecionado(s) · ' + formatarBytes(somaDaLimpeza(marcados))", $l9);
        self::assertStringContainsString('el.limpezaExcluir.disabled = marcados.length === 0;', $l9);

        $excluir = $this->funcao('excluirItens');
        self::assertStringContainsString('if (!confirm(avisoExclusaoDoLote(itens))) return;', $excluir);
        self::assertStringContainsString('postJson(cfg.urlExcluirLote, { _token: cfg.csrfLote, documentos: lote.documentos, secoes: lote.secoes })', $excluir);
        self::assertStringContainsString('if (aoExcluir) aoExcluir();', $excluir, 'o callback só roda no sucesso, depois de tirar os arquivos da memória');
    }

    #[TestDox('faixa só na raiz, fora da busca, não dispensada e com alguma sugestão viva — o caso em que as regras não acham NADA esconde a faixa')]
    public function testQuandoAFaixaAparece(): void
    {
        $r = $this->funcao('renderizarLimpeza');
        self::assertStringContainsString('const mostrar = !limpezaDispensada && !caminho.length && !buscando && sug.length > 0;', $r);
        self::assertStringContainsString('el.limpeza.hidden = !mostrar;', $r);
        self::assertStringContainsString('renderizarLimpeza(buscando);', $this->funcao('renderizar'));

        // Sugestões conferidas contra o que AINDA existe: idêntico só com cópia viva, e o que fica
        // (o mais antigo dos que restam) nunca é sugerido.
        $s = $this->funcao('sugestoesDeLimpeza');
        self::assertStringContainsString('if (!outros.length || maisAntigo(outros.concat([a])) === a) return;', $s);
        self::assertStringContainsString("const REGRAS_LIMPEZA = ['identico', 'vazio', 'copia_processo', 'muito_grande'];", $this->js());
    }

    #[TestDox('selos da linha (dc L4747): "Idêntico" vence o de sugestão; Vazio / Ver no PJe / Muito grande; a linha duplicada ganha a classe; nome parecido só no painel')]
    public function testSelosDaLinha(): void
    {
        $js = $this->js();
        self::assertStringContainsString("const SELO_SUGESTAO = { vazio: 'Vazio', copia_processo: 'Ver no PJe', muito_grande: 'Muito grande' };", $js);
        $linha = $this->funcao('linhaArquivo');
        self::assertStringContainsString('const selo = identico || seloSugestao(a);', $linha);
        self::assertStringContainsString("r.linha.classList.add('pex-item--identico');", $linha);
        self::assertStringContainsString("'Conteúdo idêntico a: '", $this->funcao('seloIdentico'));
        self::assertStringContainsString("'Sugestão para ganhar espaço: '", $this->funcao('seloSugestao'));
        self::assertStringContainsString("if (a.identicoA == null || !a.sha256) return [];", $this->funcao('copiasIdenticas'), 'sem lastro de hash, sem selo');

        self::assertStringContainsString("['Nome parecido', nomeParecidoTexto(d)],", $js);
        self::assertStringContainsString("'% parecido; confira se é o mesmo documento)'", $this->funcao('nomeParecidoTexto'));
    }

    #[TestDox('renomear/editar preservam identicoA, nomeParecidoCom e regraLimpeza: a resposta de arquivo() traz NULL e não pode apagar o par')]
    public function testMesclarPreservaOsCamposDoL9(): void
    {
        $js = $this->js();
        self::assertStringNotContainsString('Object.assign(a, res.j.documento)', $js, 'merge cru apagaria o par de idênticos');
        self::assertSame(2, substr_count($js, 'mesclarDocumento(a, res.j.documento);'), 'renomear inline e o modal de edição');

        $mesclar = $this->funcao('mesclarDocumento');
        self::assertStringContainsString('const calculados = { identicoA: a.identicoA, nomeParecidoCom: a.nomeParecidoCom, regraLimpeza: a.regraLimpeza };', $mesclar);
        self::assertStringContainsString('Object.assign(a, novo, calculados);', $mesclar, 'os calculados vêm POR ÚLTIMO e vencem o NULL da resposta');
    }

    #[TestDox('por nível (dc expLimpeza(itens)): o par de idênticos é só entre arquivos da mesma seção; a faixa só com ids do servidor que continuam na raiz')]
    public function testCalculoPorNivel(): void
    {
        self::assertStringContainsString("function nivelDe(a) { return a.secaoId == null ? null : Number(a.secaoId); }", $this->js());
        self::assertStringContainsString('x.sha256 === a.sha256 && nivelDe(x) === nivel', $this->funcao('copiasIdenticas'));
        self::assertStringContainsString('if (regraDaFaixa[a.id] !== regra || nivelDe(a) !== null) return;', $this->funcao('sugestoesDeLimpeza'), 'o Revisar nunca lista item fora da vista');
        self::assertStringContainsString('const regra = regraDe(a);', $this->funcao('seloSugestao'), 'o selo da linha vem de regraLimpeza do próprio arquivo, em qualquer nível');
    }

    #[TestDox('arranjo: a faixa mora no corpo, depois do cabeçalho das colunas e antes da lista (dc L2217); Revisar controla a lista')]
    public function testArranjoDaFaixa(): void
    {
        $twig = $this->twig();
        $cab  = strpos($twig, '<div class="pex-cabecalho" id="pexCabecalho">');
        $faix = strpos($twig, '<div class="pex-limpeza" id="pexLimpeza"');
        $lis  = strpos($twig, '<div class="pex-lista" id="pexLista"');
        self::assertNotFalse($cab);
        self::assertNotFalse($faix);
        self::assertNotFalse($lis);
        self::assertTrue($cab < $faix && $faix < $lis);
        self::assertStringContainsString('id="pexLimpezaRevisar" aria-expanded="false" aria-controls="pexLimpezaLista">Revisar</button>', $twig);
        self::assertStringContainsString('aria-label="Dispensar sugestão"', $twig);
        self::assertStringContainsString('<div class="pex-limpeza-lista" id="pexLimpezaLista" hidden>', $twig);
    }

    #[TestDox('CSS com os tokens do desenho: selo Idêntico 19px 10px/800 #8a4b00/#fff1d6/#f0c77a; linha #fffaf0 + barra #e9a23b; faixa #fffaf0/#f0d7a6; Revisar 28px #b46a00; Excluir 30px #a3232b')]
    public function testTokensDoDesenho(): void
    {
        $css = $this->css();
        foreach ([
            '--pex-dup-txt:        #8a4b00;', '--pex-dup-bg:         #fff1d6;', '--pex-dup-anel:       #f0c77a;',
            '--pex-dup-linha-bg:   #fffaf0;', '--pex-dup-barra:      #e9a23b;',
            '--pex-sug-txt:        #8f2f28;', '--pex-sug-bg:         #fdecea;', '--pex-sug-anel:       #f0b8b2;',
            '--pex-limp-bg:        #fffaf0;', '--pex-limp-anel:      #f0d7a6;', '--pex-limp-ico:       #b46a00;',
            '--pex-limp-txt:       #5c3d00;', '--pex-limp-btn:       #b46a00;', '--pex-limp-btn-hover: #94570a;',
            '--pex-limp-item-anel: #f1e2c4;', '--pex-limp-excluir:   #a3232b;',
        ] as $token) {
            self::assertStringContainsString($token, $css);
        }
        self::assertMatchesRegularExpression('/\n\.pex-selo \{[^}]*height: 19px;[^}]*padding: 0 6px;[^}]*border-radius: 3px;[^}]*font-size: 10px;[^}]*font-weight: 800;[^}]*letter-spacing: \.05em;[^}]*text-transform: uppercase;/', $css);
        self::assertStringContainsString('.pex-item.pex-item--identico { background: var(--pex-dup-linha-bg); box-shadow: inset 3px 0 0 var(--pex-dup-barra); }', $css);
        self::assertLessThan(strpos($css, '.pex-item:hover {'), strpos($css, '.pex-item.pex-item--identico {'), 'hover vence a linha duplicada (vem depois)');
        self::assertLessThan(strpos($css, '.pex-item.pex-item--sel {'), strpos($css, '.pex-item.pex-item--identico {'), 'seleção vence a linha duplicada');
        self::assertMatchesRegularExpression('/\n\.pex-limpeza-revisar \{[^}]*height: 28px;[^}]*border-radius: 3px;/', $css);
        self::assertMatchesRegularExpression('/\n\.pex-limpeza-excluir \{[^}]*height: 30px;/', $css);
        self::assertStringContainsString('.pex-limpeza[hidden] { display: none; }', $css);
        self::assertStringContainsString('.pex-limpeza-lista[hidden] { display: none; }', $css);
    }
}
