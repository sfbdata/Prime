<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Processo\Service\TribunalCnjResolver;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A janela "Vincular processo" no arranjo do desenho 02 de 18/10 (N:289-354). Arranjo testado com filho
 * direto: o que está DENTRO de quê e em que ordem. Estilo (cor, tamanho) fica no CSS, conferido pelas regras.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class VincularProcessoJanelaTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const CSS = __DIR__ . '/../../../public/css/pasta-apensados.css';

    private function janela(): Crawler
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Digite ao menos 2 caracteres', (string) $client->getResponse()->getContent());

        return $crawler->filter('#modalVincularProcesso');
    }

    #[TestDox('Moldura do desenho (N:290-351): sobreposição > caixa, cabeçalho ícone + título + fechar, rodapé Cancelar e depois Vincular com bi-check2')]
    public function testMoldura(): void
    {
        $janela = $this->janela();

        self::assertSame('vp-sobre', $janela->attr('class'));
        self::assertNotNull($janela->attr('hidden'), 'fechada até um [data-vp-abrir] abrir');
        $caixa = $janela->filter('#modalVincularProcesso > .vp-caixa[role="dialog"][aria-modal="true"][aria-labelledby="modalVincularProcessoLabel"]');
        self::assertCount(1, $caixa);
        self::assertCount(1, $caixa->filter('.vp-caixa > .vp-cab > i.bi-briefcase + b.vp-titulo#modalVincularProcessoLabel + button#mp-ampliar + button.mp-fechar[data-vp-fechar]'));
        self::assertCount(1, $caixa->filter('.vp-caixa > .vp-cab + form#formVincularProcesso.vp-form > .vp-corpo + .vp-rodape'));

        $rodape = $caixa->filter('form#formVincularProcesso > .vp-rodape');
        self::assertSame(['Cancelar', 'Vincular processo'], $rodape->children()->each(static fn (Crawler $b): string => trim($b->text())));
        self::assertCount(1, $rodape->filter('.vp-rodape > button.vp-btn.vp-btn--cancelar[data-vp-fechar] + button[type="submit"].vp-btn.vp-btn--ok > i.bi-check2'));
        self::assertCount(0, $janela->filter('.bi-check-lg, .btn, .modal-header, .modal-body, .modal-footer, [data-bs-dismiss], [data-bs-toggle]'), 'nada do Bootstrap');
    }

    #[TestDox('Lista do processo existente inline (sem position absolute, até 60vh, um rolo só) e botão de ampliar a janela no cabeçalho')]
    public function testListaInlineEAmpliar(): void
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(self::CSS));
        self::assertSame(1, preg_match('/#modalVincularProcesso \.mp-busca-lista \{([^}]*)\}/', $css, $m));
        self::assertStringNotContainsString('position: absolute', $m[1]);
        self::assertStringContainsString('60vh', $m[1]);
        self::assertStringContainsString('#modalVincularProcesso .vp-caixa.is-ampliada', $css);

        self::assertCount(1, $this->janela()->filter('.vp-cab > button#mp-ampliar.mp-fechar > i.bi-arrows-fullscreen'));
    }

    #[TestDox('Sem validação do navegador: form novalidate e nenhum required (o "Preencha: ..." é no clique)')]
    public function testSemBalaoDoNavegador(): void
    {
        $form = $this->janela()->filter('form#formVincularProcesso');

        self::assertNotNull($form->attr('novalidate'));
        self::assertCount(0, $form->filter('[required]'));
    }

    #[TestDox('Busca existente: rótulo, hidden processo_id, caixa do escolhido (oculta) com Trocar, input com seta e a lista, dica')]
    public function testBuscaDeProcesso(): void
    {
        $janela = $this->janela();
        $bloco  = $janela->filter('.vp-corpo > #modal-modo-existente');

        self::assertCount(1, $bloco->filter('#modal-modo-existente > label.mp-rotulo[for="mp-busca-input"] > .mp-obrig'));
        self::assertCount(1, $bloco->filter('#modal-modo-existente > input[type="hidden"]#mp-processoExistente[name="processo_id"]'));
        $sel = $bloco->filter('#modal-modo-existente > .mp-busca-sel[hidden]');
        self::assertCount(1, $sel->filter('.mp-busca-sel > i.bi-check2-circle + .mp-busca-sel-txt + button.mp-busca-trocar[title="Trocar"] > i.bi-x-lg'));
        self::assertCount(1, $bloco->filter('#modal-modo-existente > .mp-busca-caixa > input#mp-busca-input[role="combobox"] + i.bi-caret-down-fill + #mp-busca-lista[role="listbox"]'));
        self::assertSame('Digite número, classe, assunto ou tribunal…', $bloco->filter('#mp-busca-input')->attr('placeholder'));
        self::assertCount(1, $bloco->filter('#modal-modo-existente > .mp-busca-caixa + span.mp-dica-busca'));
        self::assertCount(0, $janela->filter('select#mp-processoExistente'), 'sem o Select2');
        self::assertCount(1, $janela->filter('.vp-corpo > .mp-abas > button.mp-aba.is-ativa#mp-modo-existente[data-vp-modo="existente"] + button.mp-aba#mp-modo-novo[data-vp-modo="novo"]'));
    }

    #[TestDox('Cadastrar novo: sub-abas; grade com número (4), botão do CNJ (2) com o "Dados localizados" embaixo e tribunal só leitura')]
    public function testCadastroNovo(): void
    {
        $novo = $this->janela()->filter('.vp-corpo > #modal-modo-novo');

        self::assertSame(
            ['Informações', 'Partes', 'Movimentações'],
            $novo->filter('#modal-modo-novo > .processo-modal-tabs > button.processo-modal-tab')->each(static fn (Crawler $b): string => trim($b->text())),
        );
        $grade = $novo->filter('#modal-modo-novo > #tab-modal-proc-info > .mp-grade');
        self::assertCount(1, $grade->filter('.mp-grade > label.mp-col-4 > input#mp-numeroProcesso[name="numeroProcesso"]'));
        self::assertCount(1, $grade->filter('.mp-grade > label.mp-col-4 + .mp-cnj.mp-col-2 > button#mp-btn-datajud > i.bi-search + span#mp-cnj-texto'));
        self::assertCount(1, $grade->filter('.mp-cnj > button#mp-btn-datajud + span.mp-cnj-ok#mp-cnj-ok'));
        self::assertCount(1, $grade->filter('.mp-grade > label.mp-col-3 > input#mp-siglaTribunal.mp-ctrl--leitura[readonly]'));
        self::assertCount(5, $grade->filter('.mp-grade > .mp-flags > label.mp-campo > select'));
        self::assertCount(0, $novo->filter('#mp-loadingOverlay'));
    }

    #[TestDox('Partes e movimentações: "Adicionar" acima, linhas na grade com 4 campos e a lixeira (bi-trash3, desabilitada) como filho direto')]
    public function testLinhas(): void
    {
        $novo = $this->janela()->filter('#modal-modo-novo');

        self::assertCount(1, $novo->filter('#tab-modal-proc-partes > .mp-add-linha > button#mp-btn-add-parte.mp-btn-add'));
        self::assertCount(1, $novo->filter('#tab-modal-proc-partes > .mp-add-linha + #mp-partesContainer.mp-linhas'));
        $parte = $novo->filter('#mp-partesContainer > .mp-parte-row.mp-linha.mp-linha--parte');
        self::assertCount(1, $parte);
        self::assertCount(4, $parte->filter('.mp-parte-row > label.mp-campo'));
        self::assertCount(1, $parte->filter('.mp-parte-row > label.mp-campo + button.mp-lixeira.mp-remove-parte[disabled] > i.bi-trash3'));
        foreach (['select[name="partes[0][tipo]"]', 'input[name="partes[0][nome]"]', 'input.mp-doc[name="partes[0][documento]"]', 'input[name="partes[0][papel]"]'] as $campo) {
            self::assertCount(1, $parte->filter('.mp-parte-row > label.mp-campo > ' . $campo), $campo);
        }

        self::assertCount(1, $novo->filter('#tab-modal-proc-movimentacoes > .mp-add-linha > button#mp-btn-add-movimentacao.mp-btn-add'));
        $mov = $novo->filter('#mp-movimentacoesContainer > .mp-movimentacao-row.mp-linha.mp-linha--mov');
        self::assertCount(1, $mov);
        self::assertCount(1, $mov->filter('.mp-movimentacao-row > label.mp-campo + button.mp-lixeira.mp-remove-movimentacao[disabled] > i.bi-trash3'));
        foreach (['dataMovimentacao', 'descricao', 'tipo', 'orgao'] as $campo) {
            self::assertCount(1, $mov->filter('.mp-movimentacao-row > label.mp-campo > input[name="movimentacoes[0][' . $campo . ']"]'), $campo);
        }
        self::assertCount(1, $novo->filter('#tab-modal-proc-movimentacoes > #mp-movimentacoesContainer + p.mp-nota'));
    }

    #[TestDox('CSS com os tokens do desenho: sobreposição, caixa de 860px, campos, grade, sub-abas, botões, linhas, aviso e par escuro')]
    public function testCssDoDesenho(): void
    {
        $css = (string) file_get_contents(self::CSS);

        foreach ([
            '.vp-sobre { position: fixed; inset: 0; z-index: 2147483400; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(16, 36, 48, .32); }',
            '#modalVincularProcesso .vp-caixa { width: min(860px, 100%); max-height: calc(100vh - 32px);',
            '#modalVincularProcesso .vp-corpo { display: flex; flex-direction: column; gap: 12px; padding: 14px 16px; overflow: auto;',
            '#modalVincularProcesso .vp-rodape { display: flex; gap: 8px; justify-content: flex-end; padding: 10px 16px; border-top: 1px solid #eef2f5; background: #fafcfd; }',
            '#modalVincularProcesso .vp-btn--ok:focus-visible { background: #085a83; }',
            '#modalVincularProcesso .mp-rotulo { margin: 0; white-space: nowrap; font-size: 12.5px; font-weight: 700; color: #34505f; }',
            '#modalVincularProcesso .mp-obrig { color: #c0392b; }',
            "height: 34px;\n    box-sizing: border-box;\n    width: 100%;\n    border: 1px solid #d3dde4;\n    border-radius: 4px;\n    padding: 0 10px;",
            '#modalVincularProcesso .mp-grade { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 10px 12px;',
            '#modalVincularProcesso .mp-dica { font-size: 11.5px; font-weight: 400; color: #7b93a2; }',
            '#modalVincularProcesso .mp-dica-busca { font-size: 12px; font-weight: 400; color: #5b7383; }',
            '#modalVincularProcesso .mp-cnj { display: flex; flex-direction: column; gap: 4px; padding-top: 20px; }',
            '#modalVincularProcesso .mp-cnj-btn:focus-visible { background: #085a83; }',
            '#modalVincularProcesso .mp-cnj-ok { font-size: 11.5px; color: #1e7b45; }',
            '#modalVincularProcesso .mp-ctrl--leitura { background: #f6f8fa; color: #34505f; }',
            '#modalVincularProcesso .processo-modal-tabs { display: flex; gap: 4px; border-bottom: 1px solid #e3e9ee; }',
            '#modalVincularProcesso .processo-modal-tab.active { color: #0a6fa0; border-bottom-color: #0a6fa0; }',
            '#modalVincularProcesso .mp-btn-add:focus-visible { background: #0a6fa0; color: #fff; }',
            'grid-template-columns: minmax(140px, 1.2fr) minmax(0, 2.4fr) minmax(130px, 1fr) minmax(130px, 1fr) 36px;',
            'grid-template-columns: minmax(140px, 1fr) minmax(0, 2.4fr) minmax(130px, 1.4fr) minmax(130px, 1fr) 36px;',
            '#modalVincularProcesso .mp-linha { display: grid; gap: 10px; padding: 12px; border: 1px solid #e3e9ee; border-radius: 4px; background: #fafcfd; }',
            '#modalVincularProcesso .mp-lixeira:not(:disabled):focus-visible { background: #fdecea; color: #a3232b; }',
            '#modalVincularProcesso .mp-busca-num { font-size: 13.5px; font-weight: 700; color: #0a6fa0;',
            '#modalVincularProcesso .mp-busca-sub { font-size: 12px;',
            '    height: 38px;',
            '#modalVincularProcesso .mp-aviso--erro { background: #fdeceb; color: #a3232b; border: 1px solid #f2c9c6; }',
            '[data-bs-theme="dark"] #modalVincularProcesso .mp-ctrl { background: var(--bs-body-bg);',
            '[data-bs-theme="dark"] #modalVincularProcesso .mp-linha { background: var(--bs-tertiary-bg);',
        ] as $regra) {
            self::assertStringContainsString($regra, $css);
        }
    }

    #[TestDox('Tribunal enquanto digita (procTrib): a janela leva a tabela do TribunalCnjResolver e ela dá a mesma sigla que o servidor grava')]
    public function testTabelaDosTribunaisDoServidor(): void
    {
        $tabela = json_decode((string) $this->janela()->attr('data-tribunais'), true, 512, JSON_THROW_ON_ERROR);
        $resolver = static::getContainer()->get(TribunalCnjResolver::class);
        self::assertSame($resolver->tabelaDeSiglas(), $tabela);

        // A mesma conta do JS (`mpProcTrib`): J no 14º dígito, TR nos dois seguintes.
        $doJs = static fn (string $n): string => (string) ($tabela[$n[13]][substr($n, 14, 2)] ?? '');
        foreach (['07069580320218070018' => 'TJDFT', '10701670520244013400' => 'TRF1', '00000011220245100001' => 'TRT10', '00000011220246070001' => 'TRE-DF'] as $numero => $sigla) {
            self::assertSame($sigla, $resolver->resolverSigla($numero));
            self::assertSame($sigla, $doJs((string) $numero), $numero);
        }
    }

    #[TestDox('Buscar no CNJ: um title só, com o texto do catálogo, e nenhum atributo solto')]
    public function testDicaDoBuscarNoCnj(): void
    {
        $botao = $this->janela()->filter('#mp-btn-datajud');
        self::assertCount(1, $botao);
        self::assertSame('Busca o processo na base de dados do CNJ', $botao->attr('title'));
        $node = $botao->getNode(0);
        self::assertInstanceOf(\DOMElement::class, $node);
        $nomes = [];
        foreach ($node->attributes as $atributo) {
            $nomes[] = $atributo->nodeName;
        }
        sort($nomes);
        self::assertSame(['class', 'id', 'title', 'type'], $nomes);
    }

    #[TestDox('Sem d-none do Bootstrap na janela: cadastro novo, confirmação do CNJ e aviso começam com hidden')]
    public function testEscondidosPeloHidden(): void
    {
        $janela = $this->janela();
        self::assertCount(0, $janela->filter('.d-none'));
        foreach (['#modal-modo-novo', '#mp-cnj-ok', '#mp-feedbackAlerta', '#mp-busca-sel', '#mp-busca-lista'] as $parte) {
            self::assertNotNull($janela->filter($parte)->attr('hidden'), $parte);
        }
    }

    #[TestDox('Abas com papel de aba: role="tab" e aria-selected nas abas Buscar/Cadastrar e nas sub-abas')]
    public function testAbasComRoleTab(): void
    {
        $janela = $this->janela();
        self::assertSame(['true', 'false'], $janela->filter('.mp-abas[role="tablist"] > button.mp-aba[role="tab"]')->each(static fn (Crawler $b): string => (string) $b->attr('aria-selected')));
        self::assertSame(['true', 'false', 'false'], $janela->filter('.processo-modal-tabs[role="tablist"] > button.processo-modal-tab[role="tab"]')->each(static fn (Crawler $b): string => (string) $b->attr('aria-selected')));
        self::assertCount(0, $janela->filter('[aria-pressed]'));
    }
}
