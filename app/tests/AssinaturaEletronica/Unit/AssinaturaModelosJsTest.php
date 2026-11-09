<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Aba Modelos, "Criar novo modelo" do passo 1 e editor de modelo (contrato da etapa 4, seção 8), lidos no
 * `public/js/assinatura.js` como FOLHA: não há navegador no PHPUnit. O que se prova é o arranjo do desenho na ordem
 * dos filhos, os textos do desenho no catálogo, os limites de cada campo, a confirmação do Excluir e que nada além de
 * blocos vai ao servidor. Ver o editor funcionando é smoke do dono.
 */
#[CoversNothing]
final class AssinaturaModelosJsTest extends TestCase
{
    private const APP = __DIR__ . '/../../..';

    private function js(): string
    {
        return (string) file_get_contents(self::APP . '/public/js/assinatura.js');
    }

    /** Corpo de uma função do primeiro nível do arquivo (4 espaços de recuo), até a chave que a fecha. */
    private function funcao(string $nome): string
    {
        $js = $this->js();
        $inicio = strpos($js, 'function ' . $nome . '(');
        self::assertNotFalse($inicio, 'função ' . $nome . ' não encontrada');
        $fim = strpos($js, "\n    }\n", $inicio);
        self::assertNotFalse($fim, 'fim da função ' . $nome);

        return substr($js, $inicio, $fim - $inicio);
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

    /** @return array<string, mixed> */
    private static function catalogo(): array
    {
        $yaml = Yaml::parseFile(self::APP . '/translations/assinatura_eletronica_tela/messages+intl-icu.pt_BR.yaml');

        return $yaml['assinatura_eletronica_tela']['js'];
    }

    #[TestDox('aba Modelos: topo com o texto e "Criar novo modelo" (.bjaP com bi-plus-lg), depois o corpo com os grupos e a coluna de ações')]
    public function testAbaModelos(): void
    {
        self::emOrdem($this->funcao('desenharLista'), [
            "'<div class=\"bjaModsTopo\"><span class=\"bjaModsTx\">' + esc(T('modelos_intro')) + '</span>'",
            "(url.modeloCriar ? '<button type=\"button\" class=\"bjaP\" data-bja-acao=\"mod-criar\"><i class=\"bi bi-plus-lg\"></i>' + esc(T('mod.criar')) + '</button>' : '')",
            "'</div><div class=\"bjaModsCorpo\">' + gruposHtml('', true) + '</div>'",
        ], 'aba Modelos');
    }

    #[TestDox('cartão: .bjaModCx > .bjaMod + .bjaModAc; Editar e Excluir no do escritório, Duplicar e adaptar no pronto, cada um com a sua URL')]
    public function testCartaoEAcoes(): void
    {
        self::assertStringContainsString(
            "return '<div class=\"bjaModCx\">' + bt + (ac ? '<span class=\"bjaModAc\">' + ac + '</span>' : '') + '</div>';",
            $this->funcao('gruposHtml'),
        );

        $acoes = $this->funcao('acoesDoModelo');
        self::assertStringContainsString("'<button type=\"button\" class=\"bjaB bjaI' + (perigo ? ' bjaR' : '') + '\" data-bja-acao=\"' + acao + '\" data-bja-mod=\"' + esc(m.id) + '\" aria-label=\"' + esc(rotulo) + '\" data-tip=\"' + esc(dica) + '\"><i class=\"bi ' + ic + '\"></i></button>'", $acoes);
        self::assertStringContainsString("if (!m.meu) { return url.modeloCriar ? bt('mod-duplicar', 'bi-copy', T('mod.duplicar'), T('mod.duplicar'), false) : ''; }", $acoes);
        self::emOrdem($acoes, [
            "(url.modeloSalvar ? bt('mod-editar', 'bi-pencil', T('mod.editar'), T('mod.editar_dica'), false) : '')",
            "(url.modeloExcluir ? bt('mod-excluir', 'bi-trash3', T('mod.excluir'), T('mod.excluir_dica'), true) : '')",
        ], 'ações do modelo do escritório');
    }

    #[TestDox('modelos do escritório vêm antes dos prontos, com a chave esc-<id> como id e só o papel de quem assina')]
    public function testModelosDoEscritorio(): void
    {
        $js = $this->js();
        self::assertStringContainsString("const modelos = () => s.meus.concat(window.BJAssinaModelos || []);", $js);
        self::assertStringContainsString("id: x.chave, idServ: x.id, t: x.nome, cat: x.categoria, layout: x.layout || '', lateral: x.lateral || '', limpa: !!x.folhaBranca,", $js);
        self::assertStringContainsString("sign: (x.signatarios || []).map(y => ({ papel: y.papel, nome: '', email: '' }))", $js);
        self::assertStringContainsString("carregar();\n    carregarModelos();\n})();", $js);
    }

    #[TestDox('passo 1: a linha de busca tem o campo e "Criar novo modelo" (.bjaB de 34 px), e a lista segue sem coluna de ações')]
    public function testPasso1(): void
    {
        $passo1 = $this->funcao('passo1');
        self::emOrdem($passo1, [
            "'<div class=\"bjaLinBusca\"><input class=\"bjaF\" type=\"text\" data-bja-mbusca",
            "(url.modeloCriar ? '<button type=\"button\" class=\"bjaB\" data-bja-acao=\"mod-criar\"><i class=\"bi bi-plus-lg\"></i>' + esc(T('mod.criar')) + '</button>' : '') + '</div>'",
            "gruposHtml(s.mBusca, false)",
        ], 'passo 1');
        self::assertStringContainsString('.bja .bjaLinBusca > .bjaB { height: 34px; }', $this->css());
    }

    #[TestDox('editor: topo (Modelos, título, Importar Word, Salvar modelo), cartão (campos, barra, criar, texto), erro e prévia, nessa ordem')]
    public function testArranjoDoEditor(): void
    {
        self::emOrdem($this->funcao('desenharMod'), [
            "'<div class=\"bjaTopoMod\"><button type=\"button\" class=\"bjaB bjaN\" data-bja-acao=\"mod-sair\"><i class=\"bi bi-arrow-left\"></i>' + esc(T('mod.voltar')) + '</button>'",
            "'<h1 class=\"bjaH1Mod\">' + esc(m.id ? T('mod.titulo_editar') : T('mod.titulo_novo')) + '</h1>'",
            "'<label class=\"bjaB bjaMdImp\"><i class=\"bi bi-file-earmark-word\"></i>' + esc(T('mod.importar')) + '<input type=\"file\" accept=\".docx\" data-bja-impword hidden></label>'",
            "'<button type=\"button\" class=\"bjaP\" data-bja-acao=\"mod-salvar\"><i class=\"bi bi-check2\"></i>' + esc(T('mod.salvar')) + '</button></div>'",
            "'<div class=\"bjaGrid\"><div class=\"bjaCent bjaMdEdCol\"><section class=\"bjaCard bjaOculto\">'",
            "'<div class=\"bjaMdCampos\">' + mdCamposHtml() + '</div>'",
            "'<div class=\"bjaMdBarra\">' + mdBarraHtml() + '</div>'",
            "'<div class=\"bjaMdCriar\"><div class=\"bjaMdPar\"><input class=\"bjaF\" type=\"text\" data-bja-md-novo maxlength=\"60\"",
            "'<button type=\"button\" class=\"bjaB\" data-bja-acao=\"mod-campo\">' + esc(T('mod.criar_campo')) + '</button></div>'",
            "'<div class=\"bjaMdPar\"><input class=\"bjaF\" type=\"text\" data-bja-md-papel maxlength=\"120\"",
            "'<button type=\"button\" class=\"bjaB\" data-bja-acao=\"mod-assinatura\"><i class=\"bi bi-pen\"></i>' + esc(T('mod.assinatura')) + '</button></div></div>'",
            "'<div class=\"bjaEd\" data-bja-editor role=\"textbox\" aria-multiline=\"true\" aria-label=\"' + esc(T('mod.texto')) + '\"></div>'",
            "'</section><div class=\"bjaMdErro\" data-bja-mderro aria-live=\"polite\">' + esc(s.mdErro) + '</div></div>'",
            "'<div class=\"bjaCent bjaMdPvCol\"><div class=\"bjaPv\" data-folha=\"m\"></div></div></div>'",
        ], 'editor');
    }

    #[TestDox('campos do editor com os limites do servidor: nome 200, categoria 80 com a lista, formato, folha branca e texto lateral 20.000 só na folha única')]
    public function testCamposDoEditor(): void
    {
        self::emOrdem($this->funcao('mdCamposHtml'), [
            "esc(T('mod.nome')) + '<input class=\"bjaF\" type=\"text\" data-bja-md=\"t\" maxlength=\"200\"",
            "esc(T('mod.categoria')) + '<input class=\"bjaF\" type=\"text\" data-bja-md=\"cat\" maxlength=\"80\" list=\"bjaCats\"",
            "<datalist id=\"bjaCats\">' + cats.map(c => '<option value=\"' + esc(c) + '\"></option>').join('') + '</datalist></label>'",
            "esc(T('mod.formato')) + '<select class=\"bjaF\" data-bja-md=\"layout\"><option value=\"\"",
            "esc(T('mod.formato_inteira'))",
            "<option value=\"2col\"' + (m.layout === '2col' ? ' selected' : '')",
            "esc(T('mod.formato_2col'))",
            "'<label class=\"bjaChk bjaMdLimpa\"><input type=\"checkbox\" data-bja-md=\"limpa\"' + (m.limpa ? ' checked' : '') + '><span><b>' + esc(T('p2.folha_branca')) + '</b> ' + esc(T('p2.folha_branca_det')) + '</span></label>'",
            "'<label class=\"bjaL\" data-bja-md-lat' + (m.layout === '2col' ? '' : ' hidden') + '>' + esc(T('mod.lateral')) + '<input class=\"bjaF\" type=\"text\" data-bja-md=\"lateral\" maxlength=\"20000\"",
        ], 'campos do editor');
    }

    #[TestDox('barra: Negrito, Título, Subtítulo, Seção, Parágrafo e à direita, divisor e "Inserir campo" com Cliente, Escritório e Local e data; o clique não tira o foco do texto')]
    public function testBarraDoEditor(): void
    {
        $barra = $this->funcao('mdBarraHtml');
        self::assertStringContainsString("[['bi-type-bold', 'negrito', 'bold'], ['bi-type-h1', 'titulo', 'h1'], ['bi-type-h2', 'subtitulo', 'h2'], ['bi-type-h3', 'secao', 'h3'], ['bi-text-paragraph', 'paragrafo', 'p'], ['bi-text-right', '', 'dir']]", $barra);
        self::assertStringContainsString("grupos = [['grupo_cliente', C0.Cliente], ['grupo_escritorio', C0.Escritorio], ['grupo_local', C0.Local]];", $barra);
        self::emOrdem($barra, [
            "'<button type=\"button\" class=\"bjaB bjaN\" data-bja-acao=\"mod-ed\" data-bja-ed=\"' + cmd + '\" aria-label=\"' + esc(dica) + '\" data-tip=\"' + esc(dica) + '\">",
            "'<span class=\"bjaMdDiv\"></span><select class=\"bjaF bjaMdIns\" data-bja-ins aria-label=\"' + esc(T('mod.inserir')) + '\"><option value=\"\">' + esc(T('mod.inserir_op')) + '</option>'",
            "'<optgroup label=\"' + esc(T('mod.' + r)) + '\">'",
        ], 'barra');
        self::assertStringContainsString("raiz.addEventListener('mousedown', ev => { if (ev.target.closest && ev.target.closest('[data-bja-ed]')) { ev.preventDefault(); } });", $this->js());
    }

    #[TestDox('salvar manda só nome, categoria, formato, lateral, folha branca, campos, signatários e blocos: nenhum HTML vai ao servidor')]
    public function testSoBlocosVaoAoServidor(): void
    {
        $salvar = $this->funcao('salvarMod');
        self::assertSame(1, preg_match('/const corpo = \{ (.+?) \};/', $salvar, $achado));
        preg_match_all('/(?:^|, )([a-zA-Z]+):/', $achado[1], $chaves);
        self::assertSame(['nome', 'categoria', 'layout', 'lateral', 'folhaBranca', 'campos', 'signatarios', 'corpo'], $chaves[1]);
        self::assertStringContainsString('signatarios: r.sign.map(x => ({ papel: x.papel }))', $salvar);
        self::assertStringNotContainsString('innerHTML', $salvar);
        self::assertStringContainsString('await postJson(m.id ? comId(url.modeloSalvar, m.id) : url.modeloCriar, corpo);', $salvar);
    }

    #[TestDox('salvar confere na ordem do desenho (nome, texto, assinatura, título na folha única) antes de chamar o servidor')]
    public function testConferenciasDoDesenho(): void
    {
        self::emOrdem($this->funcao('salvarMod'), [
            "if (!m.t.trim()) { erroMd(T('mod.erro_nome')); return; }",
            "if (!r.corpo.some(b => b.p != null)) { erroMd(T('mod.erro_texto')); return; }",
            "if (!r.sign.length) { erroMd(T('mod.erro_assinatura')); return; }",
            "if (m.layout === '2col' && !r.corpo.some(b => b.t != null)) { erroMd(T('mod.erro_titulo')); return; }",
            'await postJson(',
        ], 'conferências');
    }

    #[TestDox('erro do servidor ao salvar: aviso vermelho com o texto e o editor fica; 404 volta à aba Modelos; sucesso volta com "Modelo salvo."')]
    public function testErroDoServidorAoSalvar(): void
    {
        self::emOrdem($this->funcao('salvarMod'), [
            '} catch (e) {',
            'falha(e);',
            'if (e.status === 404) { irParaModelos(); await carregarModelos(); }',
            'return;',
            'irParaModelos();',
            "avisar(T('mod.salvo'));",
            'await carregarModelos();',
        ], 'salvar');
        self::assertStringContainsString("const falha = e => { if (e && !e.texto) { console.error(e); } avisar((e && e.texto) || T('erro_geral'), 'erro'); };", $this->js());
        self::assertStringContainsString("erro.status = r.status; throw erro;", $this->funcao('pedir'));
        self::assertStringContainsString("s.tela = 'lista'; s.w = null; s.det = null; s.aba = 'mods';", $this->funcao('irParaModelos'));
    }

    #[TestDox('"Criar novo modelo" sai do assistente (o estado dele é limpo) e Duplicar e adaptar abre o editor com "(cópia)" em Meus modelos')]
    public function testAbrirEditor(): void
    {
        self::assertStringContainsString('s.w = null; s.det = null; s.ctx = null; s.exc = null;', $this->funcao('abrirMod'));
        $acao = $this->funcao('acaoDoModelo');
        self::assertStringContainsString("case 'mod-criar': abrirMod({}); break;", $acao);
        self::assertStringContainsString("case 'mod-editar': if (m && m.meu) { abrirMod({ id: m.idServ, t: m.t, cat: m.cat, layout: m.layout, lateral: m.lateral, limpa: !!m.limpa, html: htmlDe(m) }); } break;", $acao);
        self::assertStringContainsString("case 'mod-duplicar': if (m) { abrirMod({ t: T('mod.copia', { nome: m.t }), cat: T('mod.cat_padrao'), layout: m.layout || '', lateral: m.lateral || '', html: htmlDe(m) }); } break;", $acao);
        self::assertStringContainsString("case 'mod-sair': irParaModelos(); break;", $acao);
    }

    #[TestDox('Excluir pede confirmação na janela do Excluir (título, texto, Voltar e Excluir) e avisa em amarelo depois')]
    public function testExcluirComConfirmacao(): void
    {
        self::emOrdem($this->funcao('excHtml'), [
            'if (s.exc.mod) {',
            "'<div class=\"bjaHd is-perigo\"><i class=\"bi bi-trash3\"></i>' + esc(T('mod.exc_titulo')) + '</div>'",
            "'<div class=\"bjaModalTx\">' + esc(T('mod.exc_texto')) + '</div>'",
            "data-bja-acao=\"exc-nao\">' + esc(T('exc.voltar')) + '</button>'",
            "'<button type=\"button\" class=\"bjaB bjaR\" data-bja-acao=\"exc-sim\">' + esc(T('mod.exc_botao')) + '</button></div></div></div>'",
        ], 'janela do Excluir');
        self::assertStringContainsString("case 'exc-sim': if (s.exc.mod) { excluirModelo(s.exc.mod.id); }", $this->js());
        self::emOrdem($this->funcao('excluirModelo'), ["pedir(comId(url.modeloExcluir, id), { method: 'POST' })", "avisar(T('mod.excluido'), 'aviso');"], 'excluir');
    }

    #[TestDox('htmlDe escapa cada pedaço (texto, chave, rótulo, papel); o desenho escapava só & e <')]
    public function testHtmlDeEscapa(): void
    {
        $htmlDe = $this->funcao('htmlDe');
        self::assertStringContainsString("'<span class=\"ch\" contenteditable=\"false\" data-c=\"' + esc(k[1]) + '\">' + esc(lab[k[1]] || k[1]) + '</span>' : esc(p);", $htmlDe);
        self::assertStringContainsString("data-p=\"' + esc(papel(b.a)) + '\" data-r=\"' + esc(b.r || '') + '\">' + esc(T('mod.assinatura_de', { papel: papel(b.a) }))", $htmlDe);
        self::assertStringNotContainsString(".replace(/&/g, '&amp;').replace(/</g, '&lt;')", $this->js());
    }

    #[TestDox('Importar Word lê o HTML do mammoth com DOMParser (nada executa) e escapa o texto; lerEd marca os campos num Set')]
    public function testImportarWordELerEd(): void
    {
        $importar = $this->funcao('importarWord');
        self::assertStringContainsString("new DOMParser().parseFromString(String(r.value || ''), 'text/html').body", $importar);
        self::assertStringContainsString('c.nodeType === 3 ? esc(c.nodeValue)', $importar);
        self::assertStringNotContainsString("createElement('div')", $importar);
        self::assertStringContainsString("avisar(T('mod.importar_erro'), 'erro');", $importar);

        $lerEd = $this->funcao('lerEd');
        self::assertStringContainsString('visto = new Set()', $lerEd);
        self::assertStringContainsString("new DOMParser().parseFromString('<body>' + m.html + '</body>', 'text/html').body", $lerEd);
    }

    #[TestDox('"Criar campo" com colchete mostra o erro na linha do editor; nada é cortado')]
    public function testCriarCampoComColchete(): void
    {
        self::assertStringContainsString("if (/[[\\]]/.test(r)) { erroMd(T('mod.erro_colchete')); return; }", $this->funcao('criarCampo'));
    }

    #[TestDox('prévia do editor: blocos do texto com o escritório preenchido e a logo da BlueJus')]
    public function testPreviaDoEditor(): void
    {
        self::assertStringContainsString("F.pintar(pv, { blocos: blocos(lerEd(), padrao(), true), sign: null, logo: logos.bluejus || '', layout: m.layout, lateral: m.lateral, limpa: !!m.limpa, cod: '', hash: '', fonte: null });", $this->funcao('pintarPrevias'));
    }

    #[TestDox('os textos da aba Modelos e do editor são os do desenho')]
    public function testTextosDoDesenho(): void
    {
        $mod = self::catalogo()['mod'];
        $esperado = [
            'criar' => 'Criar novo modelo', 'editar' => 'Editar modelo', 'editar_dica' => 'Editar', 'excluir' => 'Excluir modelo',
            'excluir_dica' => 'Excluir', 'duplicar' => 'Duplicar e adaptar', 'voltar' => 'Modelos', 'titulo_novo' => 'Novo modelo',
            'titulo_editar' => 'Editar modelo', 'importar' => 'Importar Word', 'salvar' => 'Salvar modelo', 'nome' => 'Nome do modelo',
            'categoria' => 'Categoria', 'cat_padrao' => 'Meus modelos', 'formato' => 'Formato', 'formato_inteira' => 'Página inteira',
            'formato_2col' => 'Folha única em duas colunas', 'lateral' => 'Texto lateral', 'negrito' => 'Negrito', 'titulo' => 'Título',
            'subtitulo' => 'Subtítulo', 'secao' => 'Seção', 'paragrafo' => 'Parágrafo', 'direita' => 'Alinhar à direita',
            'inserir' => 'Inserir campo', 'inserir_op' => 'Inserir campo...', 'grupo_cliente' => 'Cliente', 'grupo_escritorio' => 'Escritório',
            'grupo_local' => 'Local e data', 'novo_campo' => 'Novo campo', 'novo_campo_ex' => 'Novo campo (ex.: Número do processo)',
            'criar_campo' => 'Criar campo', 'papel' => 'Papel do signatário', 'papel_ex' => 'Quem assina (ex.: Outorgante)',
            'papel_padrao' => 'Signatário', 'assinatura' => 'Assinatura', 'assinatura_de' => 'Assinatura: {papel}', 'texto' => 'Texto do modelo',
            'inicial_titulo' => 'TÍTULO DO DOCUMENTO', 'inicial_texto' => 'Digite aqui o texto do modelo. Use Inserir campo para o que muda em cada documento.',
            'copia' => '{nome} (cópia)', 'erro_nome' => 'Informe o nome do modelo.', 'erro_texto' => 'Escreva o texto do modelo.',
            'erro_assinatura' => 'Inclua ao menos uma assinatura.', 'erro_titulo' => 'No formato de folha única, marque uma linha como Título.',
            'salvo' => 'Modelo salvo.', 'excluido' => 'Modelo excluído. Os documentos já enviados não mudam.',
            'importado' => 'Texto importado. Marque título, campos e assinaturas.', 'importar_erro' => 'Não foi possível ler este arquivo.',
            'exc_titulo' => 'Excluir modelo', 'exc_texto' => 'O modelo será apagado e não poderá ser recuperado. Os documentos já enviados não mudam.',
            'exc_botao' => 'Excluir',
        ];
        foreach ($esperado as $chave => $texto) {
            self::assertSame($texto, $mod[$chave] ?? null, 'mod.' . $chave);
        }
        self::assertSame('Use um modelo pronto, duplique para adaptar ou crie o seu.', self::catalogo()['modelos_intro']);
    }

    #[TestDox('CSS do desenho: coluna de ações, editor de 560 e prévia de 460, grade dos campos, barra e área de texto')]
    public function testCssDoDesenho(): void
    {
        $css = $this->css();
        foreach ([
            '.bja .bjaModAc { display: flex; flex-direction: column; gap: 3px; padding: 6px; border-left: 1px solid var(--bja-div); justify-content: center; }',
            '.bja .bjaMdEdCol { flex-basis: 560px; }',
            '.bja .bjaMdPvCol { flex-basis: 460px; }',
            '.bja .bjaMdCampos { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 8px; padding: 12px 14px; border-bottom: 1px solid var(--bja-div); }',
            '.bja .bjaMdBarra { display: flex; gap: 4px; flex-wrap: wrap; align-items: center; padding: 8px 14px; background: var(--bja-tab); border-bottom: 1px solid var(--bja-borda); }',
            '.bja .bjaMdIns { width: 170px; height: 32px; }',
            '.bja .bjaEd { min-height: 380px; max-height: 62vh; overflow-y: auto; padding: 18px 22px; font-size: 14px; line-height: 1.55; outline: none;',
            '.bja .bjaEd .ch { display: inline-block; padding: 0 6px; background: #fff3cd; border: 1px solid #e0c36a; border-radius: 2px; color: #7a5200; font-size: 12.5px; }',
            '.bja .bjaMdErro { font-size: 12.5px; color: #a3232b; margin-top: 6px; }',
        ] as $regra) {
            self::assertStringContainsString($regra, $css);
        }
    }

    private function css(): string
    {
        return (string) file_get_contents(self::APP . '/public/css/assinatura.css');
    }
}
