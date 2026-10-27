<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Tests\Support\CatalogoPtBr;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * `public/js/pasta-push-link.js` lido como folha (o container de teste não tem motor de JS). Para
 * provar mais que a presença de um texto, os testes recortam o CORPO de cada função (casando as
 * chaves, fora de strings e comentários) e conferem a regra ali dentro: onde a condição está, o
 * que cada ramo faz e a ordem das chamadas. Os contratos são cruzados com o template, o CSS e o
 * pasta-show.js de verdade, não com listas copiadas.
 */
#[Group('pasta')]
final class PastaPushLinkJsTest extends TestCase
{
    private const JS = __DIR__ . '/../../../public/js/pasta-push-link.js';
    private const SHOW_JS = __DIR__ . '/../../../public/js/pasta-show.js';
    private const CSS = __DIR__ . '/../../../public/css/pasta-push-link.css';
    private const TWIG = __DIR__ . '/../../../templates/pasta/_push_link_externo.html.twig';
    private const CABECALHO = __DIR__ . '/../../../templates/pasta/_cabecalho.html.twig';

    /** Os textos do JS no catálogo (`pasta_push.js.link`, spec idioma-do-sistema, "A Pasta, aba Push"). */
    private static function texto(string $chave): string
    {
        $catalogo = CatalogoPtBr::carregar();

        return $catalogo['pasta_push']['js']['link'][$chave];
    }

    /** O código sem os comentários: o que conta é o que roda. */
    private function codigo(): string
    {
        $js = (string) file_get_contents(self::JS);
        $saida = '';
        $n = strlen($js);
        for ($i = 0; $i < $n; ++$i) {
            $c = $js[$i];
            if ($c === "'" || $c === '"') {
                $fim = $this->fimDaString($js, $i);
                $saida .= substr($js, $i, $fim - $i + 1);
                $i = $fim;
                continue;
            }
            if ($c === '/' && ($js[$i + 1] ?? '') === '*') {
                $i = (int) strpos($js, '*/', $i + 2) + 1;
                continue;
            }
            if ($c === '/' && ($js[$i + 1] ?? '') === '/') {
                $quebra = strpos($js, "\n", $i);
                $i = ($quebra === false ? $n : $quebra) - 1;
                continue;
            }
            $saida .= $c;
        }

        return $saida;
    }

    private function fimDaString(string $js, int $inicio): int
    {
        $aspa = $js[$inicio];
        for ($i = $inicio + 1, $n = strlen($js); $i < $n; ++$i) {
            if ($js[$i] === '\\') {
                ++$i;
                continue;
            }
            if ($js[$i] === $aspa) {
                return $i;
            }
        }
        self::fail('string sem fim no JS');
    }

    /** Corpo (entre as chaves) da função `function <nome>(`, casando as chaves fora das strings. */
    private function funcao(string $nome): string
    {
        $codigo = $this->codigo();
        $inicio = strpos($codigo, 'function ' . $nome . '(');
        self::assertNotFalse($inicio, "função {$nome} existe");
        $abre = (int) strpos($codigo, '{', $inicio);
        $nivel = 0;
        for ($i = $abre, $n = strlen($codigo); $i < $n; ++$i) {
            $c = $codigo[$i];
            if ($c === "'" || $c === '"') {
                $i = $this->fimDaString($codigo, $i);
                continue;
            }
            if ($c === '{') {
                ++$nivel;
            } elseif ($c === '}' && --$nivel === 0) {
                return substr($codigo, $abre + 1, $i - $abre - 1);
            }
        }
        self::fail("função {$nome} sem fim");
    }

    /** O bloco `if (<condicao>) { ... }` dentro de um trecho, casando as chaves. */
    private function blocoDoIf(string $trecho, string $condicao): string
    {
        $inicio = strpos($trecho, 'if (' . $condicao . ') {');
        self::assertNotFalse($inicio, "if ({$condicao}) existe");
        $abre = (int) strpos($trecho, '{', $inicio);
        $nivel = 0;
        for ($i = $abre, $n = strlen($trecho); $i < $n; ++$i) {
            if ($trecho[$i] === '{') {
                ++$nivel;
            } elseif ($trecho[$i] === '}' && --$nivel === 0) {
                return substr($trecho, $abre + 1, $i - $abre - 1);
            }
        }
        self::fail("if ({$condicao}) sem fim");
    }

    #[TestDox('Nunca monta HTML: sem innerHTML e afins; todo createElement recebe uma tag literal, e o dado entra por textContent/value')]
    public function testSemHtmlMontado(): void
    {
        $codigo = $this->codigo();

        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'DOMParser', 'createContextualFragment', 'eval(', 'new Function'] as $proibido) {
            self::assertStringNotContainsString($proibido, $codigo, "{$proibido} no pasta-push-link.js");
        }
        preg_match_all('/createElement\(([^)]*)\)/', $codigo, $chamadas);
        self::assertNotEmpty($chamadas[1]);
        foreach ($chamadas[1] as $arg) {
            self::assertMatchesRegularExpression("/^('[a-z]+'|tag)$/", $arg, 'tag sempre literal (o `tag` do el() só recebe literais)');
        }
        preg_match_all("/\\bel\\(([^,)]+)/", $codigo, $tags);
        foreach ($tags[1] as $tag) {
            if ($tag === 'tag') {
                continue; // a própria declaração de el()
            }
            self::assertMatchesRegularExpression("/^'[a-z]+'$/", trim($tag));
        }
        // O el() põe o texto por textContent; nenhum setAttribute recebe nome vindo de dado.
        self::assertStringContainsString('n.textContent = String(texto)', $this->funcao('el'));
        preg_match_all("/setAttribute\\(([^,]+),/", $codigo, $atributos);
        foreach ($atributos[1] as $nomeDoAtributo) {
            self::assertMatchesRegularExpression("/^'[a-z-]+'$/", trim($nomeDoAtributo));
        }
    }

    #[TestDox('É uma IIFE em modo estrito, que não faz nada sem o modal na página')]
    public function testIife(): void
    {
        $codigo = trim($this->codigo());

        self::assertStringStartsWith('(function () {', $codigo);
        self::assertStringEndsWith('})();', $codigo);
        self::assertMatchesRegularExpression("/'use strict';\\s+var raiz = document\\.getElementById\\('modalLinkExternoPush'\\);\\s+if \\(!raiz\\) \\{ return; \\}/", $codigo);
    }

    #[TestDox('Contrato com o servidor: URL e CSRF dos data-*, _token em toda escrita, os três campos do gerar e as ações por /<id>/<acao>')]
    public function testContratoComAsRotas(): void
    {
        $postar = $this->funcao('postar');
        self::assertStringContainsString("corpo.append('_token', CSRF)", $postar);
        self::assertStringContainsString("method: 'POST'", $postar);

        $gerar = $this->funcao('gerar');
        self::assertStringContainsString('postar(URL_LINKS, {', $gerar);
        foreach (['exigir_codigo', 'mostrar_cliente', 'incluir_analises'] as $campo) {
            self::assertStringContainsString("{$campo}: estado.form.{$campo} ? '1' : '0'", $gerar);
        }
        // Cria, mostra o estado criado e SÓ depois copia o texto que o servidor montou.
        self::assertLessThan(strpos($gerar, 'copiarTexto(estado.criado.textoParaCopiar)'), strpos($gerar, 'estado.criado = r.corpo.link'));
        self::assertStringContainsString("mostrarErro((r.corpo && r.corpo.erro) || ERRO_GERAL)", $gerar, 'o `erro` do 422 vai para a tela');

        self::assertStringContainsString("postar(URL_LINKS + '/' + encodeURIComponent(String(link.id)) + '/' + acao, {})", $this->funcao('acaoNoLink'));
        $codigo = $this->codigo();
        self::assertStringContainsString("acaoNoLink(link, 'renovar', BJ_T.t('pasta_push.js.link.renovado'))", $codigo);
        self::assertStringContainsString("acaoNoLink(link, 'recusar', BJ_T.t('pasta_push.js.link.recusado'))", $codigo);
        self::assertSame('Link renovado por 30 dias', self::texto('renovado'));
        self::assertSame('Renovação recusada', self::texto('recusado'));

        // As rotas existem com esse formato (o controller é a fonte).
        $controller = (string) file_get_contents(__DIR__ . '/../../../src/Pasta/Controller/PastaLinkExternoController.php');
        foreach (["'/{linkId}/revogar'", "'/{linkId}/renovar'", "'/{linkId}/recusar'", "'pasta_push_link_'"] as $rota) {
            self::assertStringContainsString($rota, $controller);
        }
    }

    #[TestDox('Revogar só segue depois do "sim" do confirmarPs, com o texto exato do desenho')]
    public function testRevogarPerguntaAntes(): void
    {
        $revogar = $this->funcao('revogar');

        self::assertStringContainsString("texto: BJ_T.t('pasta_push.js.link.revogar_pergunta')", $revogar);
        self::assertSame('Revogar este link? Quem tiver o endereço perde o acesso na hora.', self::texto('revogar_pergunta'));
        self::assertStringContainsString('window.confirmarPs({', $revogar);
        // A única chamada de escrita está dentro do `if (sim)`.
        self::assertSame(1, substr_count($revogar, 'acaoNoLink('));
        self::assertStringContainsString("acaoNoLink(link, 'revogar', BJ_T.t('pasta_push.js.link.revogado'))", $this->blocoDoIf($revogar, 'sim'));
        self::assertSame('Link revogado', self::texto('revogado'));
    }

    #[TestDox('A1: o selo do ⋮ carrega pela fase de captura, porque o pasta-show.js para a propagação do clique no gatilho')]
    public function testSeloDoMenuDaPastaPelaCaptura(): void
    {
        // O motivo: o gatilho do ⋮ faz stopPropagation (pasta-show.js, `popovers()`).
        $show = (string) file_get_contents(self::SHOW_JS);
        self::assertMatchesRegularExpression("/gatilho\\.addEventListener\\('click', function \\(e\\) \\{\\s+e\\.stopPropagation\\(\\);/", $show);
        self::assertStringContainsString('data-ps-pop="psMenuAcoes"', (string) file_get_contents(self::CABECALHO));

        $codigo = $this->codigo();
        $ouvintes = preg_split("/document\\.addEventListener\\('click', /", $codigo) ?: [];
        $doSelo = array_values(array_filter($ouvintes, static fn (string $o): bool => str_contains($o, '[data-ps-pop="psMenuAcoes"]') && str_contains($o, 'carregar()')));
        self::assertCount(1, $doSelo, 'um ouvinte de clique no document carrega a lista ao abrir o ⋮');
        $fim = strpos($doSelo[0], '}, true);');
        self::assertNotFalse($fim, 'registrado com useCapture = true');
        self::assertStringContainsString("e.target.closest('[data-ps-pop=\"psMenuAcoes\"]')", substr($doSelo[0], 0, $fim));

        // E o selo é preenchido com o totalAtivos do servidor, escondido no zero.
        $selo = $this->funcao('desenharSelo');
        self::assertStringContainsString("document.querySelector('.js-ppl-badge-ativos')", $selo);
        self::assertStringContainsString('var n = estado.dados.totalAtivos || 0;', $selo);
        self::assertStringContainsString('selo.hidden = n === 0;', $selo);
        self::assertStringContainsString('desenharSelo();', $this->funcao('desenharTudo'));
    }

    #[TestDox('A2: menu da aba bloqueado (podeGerar=false) mostra só "Link externo indisponível", mesmo com link ativo')]
    public function testMenuBloqueadoTemItemUnico(): void
    {
        $menu = $this->funcao('desenharMenu');

        self::assertStringContainsString('var bloqueado = !d.podeGerar;', $menu);
        self::assertStringContainsString('copiar.hidden = bloqueado || !ativo;', $menu);
        self::assertStringContainsString('acessos.hidden = bloqueado || lista.length === 0;', $menu);
        self::assertStringContainsString('gerarItem.hidden = bloqueado;', $menu);
        self::assertStringContainsString('indisponivel.hidden = !bloqueado;', $menu);
        // Nenhuma outra atribuição a `.hidden` dos itens fura a regra.
        self::assertSame(4, preg_match_all('/\b(copiar|acessos|gerarItem|indisponivel)\.hidden = /', $menu));

        // "Ativo" para copiar é só o estado `ativo`: o `indisponivel` não conta.
        $ativo = $this->funcao('ativoMaisRecente');
        self::assertStringContainsString("lista[i].estado === 'ativo'", $ativo);
        self::assertStringNotContainsString('indisponivel', $ativo);
    }

    #[TestDox('A2: na lista, copiar só no ativo; Revogar no ativo e no indisponível; selo cinza (padrão) para o indisponível')]
    public function testLinhaDoIndisponivel(): void
    {
        $linha = $this->funcao('linhaDaLista');

        $doAtivo = $this->blocoDoIf($linha, "link.estado === 'ativo'");
        self::assertStringContainsString('js-ppl-linha-copiar', $doAtivo);
        self::assertSame(1, substr_count($linha, 'js-ppl-linha-copiar'), 'copiar só existe no ramo do ativo');
        $doRevogar = $this->blocoDoIf($linha, "(link.estado === 'ativo' || link.estado === 'indisponivel') && link.podeRevogar");
        self::assertStringContainsString('js-ppl-linha-revogar', $doRevogar);
        self::assertSame(1, substr_count($linha, 'js-ppl-linha-revogar'));
        self::assertStringContainsString('js-ppl-linha-renovar', $this->blocoDoIf($linha, 'link.podeRenovar'));
        self::assertStringContainsString('js-ppl-linha-recusar', $this->blocoDoIf($linha, 'link.podeRecusar'));

        // Selo: `ppl-tag--<estado>`; só ativo e renovação pedida têm cor própria, o resto é o cinza da base.
        self::assertStringContainsString("'ppl-tag ppl-tag--' + link.estado", $linha);
        $css = (string) file_get_contents(self::CSS);
        self::assertStringContainsString('.ppl-tag--ativo', $css);
        self::assertStringContainsString('.ppl-tag--renovacao_pedida', $css);
        self::assertStringNotContainsString('.ppl-tag--indisponivel', $css);
        self::assertMatchesRegularExpression('/\.ppl-tag \{[^}]*background: var\(--ppl-neutro-bg\)/', $css);
    }

    #[TestDox('A3: o redesenho do modal devolve o foco a um elemento visível da .ppl-janela; a ação na linha volta para a mesma linha')]
    public function testFocoDepoisDoRedesenho(): void
    {
        $modal = $this->funcao('desenharModal');
        self::assertMatchesRegularExpression('/^\s*var antes = document\.activeElement;/', $modal, 'o foco é lido ANTES de refazer');
        self::assertMatchesRegularExpression('/desenharLista\(\);\s*focoNaJanela\(antes\);\s*$/', $modal, 'e conferido DEPOIS de tudo');
        self::assertLessThan(strpos($modal, 'desenharLista();'), strpos($modal, 'desenharBloqueio();'));

        $foco = $this->funcao('focoNaJanela');
        self::assertStringContainsString('if (raiz.hidden) { return; }', $foco);
        // Só age se o foco estava no modal (ou já tinha caído no body) e saiu de vista.
        self::assertStringContainsString('var estavaAqui = !antes || antes === document.body || raiz.contains(antes);', $foco);
        self::assertStringContainsString('agora.getClientRects().length === 0', $foco);
        self::assertStringContainsString('if (!estavaAqui || !perdeu) { return; }', $foco);
        self::assertStringContainsString("'.ppl-linha[data-link-id=\"' + Number(linkId) + '\"] .js-ppl-linha-ac'", $foco);
        self::assertStringContainsString("focaveis(q('.ppl-janela'))[0]", $foco);
        self::assertStringContainsString('n.getClientRects().length > 0', $this->funcao('focaveis'), 'só focável visível');

        $acao = $this->funcao('acaoNoLink');
        self::assertLessThan(strpos($acao, 'postar('), strpos($acao, 'estado.focoLinkId = link.id;'));
        self::assertStringContainsString('return carregar();', $acao, 'a lista é refeita pelo GET, que passa pelo desenharModal');

        // Esc continua no modal, salvo com a confirmação por cima.
        $codigo = $this->codigo();
        self::assertMatchesRegularExpression("/raiz\\.addEventListener\\('keydown', function \\(e\\) \\{\\s+if \\(confirmacaoAberta\\(\\)\\) \\{ return; \\}\\s+if \\(e\\.key === 'Escape'\\) \\{ e\\.preventDefault\\(\\); e\\.stopPropagation\\(\\); fecharModal\\(\\); return; \\}/", $codigo);
    }

    #[TestDox('A4: aviso do copiar sem "sem código", sub-linha "até/expirou em" do desenho e fundo que fecha por mousedown')]
    public function testVoltaAoDesenho(): void
    {
        $codigo = $this->codigo();

        self::assertStringContainsString(
            "copiarLink(ativo, ativo.comCodigo\n                    ? BJ_T.t('pasta_push.js.link.copiado_menu_com_codigo', { data: dataSo(ativo.expiraEm) })\n                    : BJ_T.t('pasta_push.js.link.copiado_menu', { data: dataSo(ativo.expiraEm) }));",
            $codigo,
        );
        self::assertSame('Link copiado · válido até {data} · com código de acesso. Análises com cadeado fechado não aparecem.', self::texto('copiado_menu_com_codigo'));
        self::assertSame('Link copiado · válido até {data}. Análises com cadeado fechado não aparecem.', self::texto('copiado_menu'));
        self::assertStringNotContainsString('sem código de acesso', $codigo);
        self::assertStringNotContainsString('sem código de acesso', (string) json_encode(CatalogoPtBr::carregar()['pasta_push'], JSON_UNESCAPED_UNICODE));

        $sub = $this->funcao('subDaLinha');
        self::assertStringContainsString("BJ_T.t('pasta_push.js.link.ate', { data: link.expiraEm })", $sub);
        self::assertStringContainsString("BJ_T.t('pasta_push.js.link.expirou_em', { data: link.expiraEm })", $sub);
        self::assertStringNotContainsString('revogado em', $sub);
        self::assertStringContainsString("(link.comCodigo ? BJ_T.t('pasta_push.js.link.com_codigo') : BJ_T.t('pasta_push.js.link.sem_codigo'))", $sub);
        self::assertSame(['até {data}', 'expirou em {data}', 'com código', 'sem código'], [self::texto('ate'), self::texto('expirou_em'), self::texto('com_codigo'), self::texto('sem_codigo')]);

        self::assertMatchesRegularExpression("/raiz\\.addEventListener\\('mousedown', function \\(e\\) \\{\\s+if \\(e\\.target === e\\.currentTarget\\) \\{ fecharModal\\(\\); \\}/", $codigo);
        self::assertStringNotContainsString('alvo === raiz', $codigo, 'o clique não fecha mais pelo fundo');
    }

    #[TestDox('Contrato com o template e o CSS: cada js-ppl-* procurado existe no parcial/cabeçalho e cada ppl-* criado pelo JS tem estilo')]
    public function testContratoComTemplateECss(): void
    {
        $codigo = $this->codigo();
        $twig = (string) file_get_contents(self::TWIG) . (string) file_get_contents(self::CABECALHO);
        $css = (string) file_get_contents(self::CSS);

        // Tudo que o JS procura por seletor existe do outro lado (as linhas da lista o JS cria).
        preg_match_all("/'\\.(js-ppl-[a-z-]+)/", $codigo, $procurados);
        $criadosPeloJs = [];
        preg_match_all("/(?:el|botao)\\('[a-z]*',? ?'([^']+)'|botao\\('([^']+)'/", $codigo, $criacoes);
        foreach (array_merge($criacoes[1], $criacoes[2]) as $lista) {
            foreach (preg_split('/\s+/', trim($lista)) ?: [] as $classe) {
                if ($classe !== '') {
                    $criadosPeloJs[$classe] = true;
                }
            }
        }
        self::assertNotEmpty($procurados[1]);
        foreach (array_unique($procurados[1]) as $classe) {
            self::assertTrue(
                str_contains($twig, $classe) || isset($criadosPeloJs[$classe]),
                "{$classe} é procurado pelo JS mas ninguém o cria",
            );
        }
        // Classe visual criada pelo JS tem regra no CSS (o que termina em -- é prefixo de modificador).
        foreach (array_keys($criadosPeloJs) as $classe) {
            if (str_starts_with($classe, 'js-') || str_ends_with($classe, '--') || !str_starts_with($classe, 'ppl-')) {
                continue;
            }
            self::assertStringContainsString('.' . $classe, $css, "{$classe} sem estilo");
        }
        foreach (["getElementById('pushLinkMenu')", "getElementById('pushLinkAcessos')", "closest('[data-ppl-menu]')"] as $trecho) {
            self::assertStringContainsString($trecho, $codigo);
        }
        foreach (['id="pushLinkMenu"', 'id="pushLinkAcessos"', 'data-ppl-menu="pushLinkMenu"', 'id="modalLinkExternoPush"', 'data-url-links=', 'data-csrf='] as $marca) {
            self::assertStringContainsString($marca, $twig);
        }
    }

    #[TestDox('Menu da aba: botão direito, Shift+F10 e a tecla de menu abrem; Esc fecha e devolve o foco; rótulos do desenho')]
    public function testMenuDaAba(): void
    {
        $codigo = $this->codigo();

        self::assertMatchesRegularExpression("/document\\.addEventListener\\('contextmenu', function \\(e\\) \\{\\s+var aba = e\\.target\\.closest\\('\\[data-ppl-menu\\]'\\);\\s+if \\(!aba\\) \\{ return; \\}\\s+e\\.preventDefault\\(\\);/", $codigo);
        self::assertStringContainsString("if (aba && (e.key === 'ContextMenu' || (e.shiftKey && e.key === 'F10'))) {", $codigo);
        self::assertStringContainsString("if (e.key === 'Escape') { e.preventDefault(); fecharMenu(true); return; }", $codigo);
        self::assertStringContainsString('if (devolverFoco && aba) { aba.focus(); }', $this->funcao('fecharMenu'));
        $menu = $this->funcao('desenharMenu');
        self::assertStringContainsString("ativo ? BJ_T.t('pasta_push.js.link.gerar_novo') : BJ_T.t('pasta_push.js.link.gerar')", $menu);
        self::assertStringContainsString("BJ_T.t('pasta_push.js.link.ver_acessos') + ' · ' + plural(n, BJ_T.t('pasta_push.js.link.um_acesso'), BJ_T.t('pasta_push.js.link.acessos', { n: String(n) }))", $menu);
        self::assertSame(
            ['Gerar novo link externo', 'Gerar link externo do Push', 'Ver acessos ao link', '1 acesso', '{n} acessos'],
            [self::texto('gerar_novo'), self::texto('gerar'), self::texto('ver_acessos'), self::texto('um_acesso'), self::texto('acessos')],
        );
    }

    #[TestDox('Copiar usa a área de transferência do navegador e cai no textarea quando ela falha ou não existe')]
    public function testCopiarComFallback(): void
    {
        $copiar = $this->funcao('copiarTexto');

        self::assertStringContainsString("document.createElement('textarea')", $copiar);
        self::assertStringContainsString("document.execCommand('copy')", $copiar);
        self::assertStringContainsString('return navigator.clipboard.writeText(texto).then(function () { return true; }, function () { return viaTextarea(); });', $copiar);
        self::assertStringContainsString('return Promise.resolve(viaTextarea());', $copiar);
        self::assertStringContainsString("window.open(estado.criado.url, '_blank', 'noopener')", $this->codigo());
    }

    #[TestDox('Sem travessão em nenhum texto do JS, do CSS e do parcial (regra do dono)')]
    public function testSemTravessao(): void
    {
        foreach ([self::JS, self::TWIG, self::CSS] as $arquivo) {
            self::assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', (string) file_get_contents($arquivo), basename($arquivo));
        }
    }
}
