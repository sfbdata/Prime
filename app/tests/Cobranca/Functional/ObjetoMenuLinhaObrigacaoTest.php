<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\ObjetoController;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Enum\StatusCaso;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use App\Tests\Support\CatalogoPtBr;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Menu da LINHA da obrigação (desenho "Objeto 1A" de 19/10, L165/181/214-224 e `omVals` L578-592).
 *
 * O `⋯` e o botão direito na linha abrem o MESMO menu: o botão aponta (`aria-controls`) para o
 * `id` que a linha publica em `data-obr-menu`, e o menu mora dentro da própria linha. Os itens são as
 * ações que já existiam na linha; Editar e Excluir continuam sendo os gatilhos REAIS dos modais, com
 * os `data-*` que o JS de `show.html.twig` lê no `relatedTarget`.
 *
 * Fora por decisão do dono (19/10): "Gerar boleto / 2ª via" e "Enviar cobrança ao devedor".
 *
 * O comportamento (abrir no cursor, fechar, copiar) é JS e fica com o smoke do dono; aqui se trava a
 * estrutura, com combinador de FILHO DIRETO onde o arranjo importa.
 */
#[CoversClass(ObjetoController::class)]
final class ObjetoMenuLinhaObrigacaoTest extends CobrancaWebTestCase
{
    /** @return array{0: Crawler, 1: int} */
    private function abrirComUmaObrigacao(CasoCobranca $caso, KernelBrowser $client, string $descricao = 'Taxa de condomínio 03/2026'): array
    {
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $caso->getTenant(), 'caso' => $caso,
            'descricao' => $descricao, 'valorOriginal' => 120000, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('2026-03-10'),
        ])->_real();
        $id = (int) $obrigacao->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        self::assertResponseIsSuccessful();

        return [$crawler, $id];
    }

    /** @return list<string> rótulos dos itens do menu, na ordem */
    private function rotulos(Crawler $menu): array
    {
        return $menu->filter('.cob-obr-menu-item')->each(static fn (Crawler $i): string => trim($i->text()));
    }

    #[TestDox('O ⋯ e o botão direito da linha apontam para o MESMO menu, que mora dentro da linha')]
    public function testOBotaoEALinhaApontamParaOMesmoMenu(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        [$crawler, $id] = $this->abrirComUmaObrigacao($caso, $client);

        $idMenu = 'menuObr' . $id;
        $linha = $crawler->filter('#secao-divida .jp-lista > .jp-obr[data-obr-menu="' . $idMenu . '"]');
        self::assertCount(1, $linha, 'A linha publica o menu dela para o botão direito');

        $botao = $linha->filter(':scope > .jp-obr-acoes > .cob-row-menu > button[data-obr-menu-abrir="' . $idMenu . '"]');
        self::assertCount(1, $botao, 'O ⋯ é filho das ações da própria linha');
        self::assertSame($idMenu, $botao->attr('aria-controls'));
        self::assertSame('menu', $botao->attr('aria-haspopup'));
        self::assertSame('false', $botao->attr('aria-expanded'));
        self::assertCount(1, $botao->filter('i.bi-three-dots'));
        // Não é mais dropdown do Bootstrap: quem abre é o `objeto-linha-menu.js`, nos dois gatilhos.
        self::assertNull($botao->attr('data-bs-toggle'));

        $menu = $linha->filter(':scope > .jp-obr-acoes > .cob-row-menu > #' . $idMenu);
        self::assertCount(1, $menu, 'O menu é irmão do ⋯, dentro da linha');
        self::assertSame('menu', $menu->attr('role'));
        self::assertNotNull($menu->attr('hidden'), 'O menu nasce fechado');
        self::assertCount(1, $crawler->filter('#' . $idMenu), 'Um menu por linha, sem cópia solta na página');

        self::assertCount(1, $crawler->filter('script[src*="js/objeto-linha-menu.js"]'));
        self::assertCount(1, $crawler->filter('link[href*="css/objeto-linha-menu.css"]'));
    }

    #[TestDox('O menu tem o título com a descrição e os itens do desenho que existem no sistema, na ordem')]
    public function testMenuTemTituloEItensDoDesenho(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        [$crawler, $id] = $this->abrirComUmaObrigacao($caso, $client);

        $menu = $crawler->filter('#menuObr' . $id);
        $titulo = $menu->filter(':scope > .cob-obr-menu-titulo');
        self::assertSame('Taxa de condomínio 03/2026', trim($titulo->text()));
        self::assertSame($titulo->attr('id'), $menu->attr('aria-labelledby'));

        self::assertSame([
            'Receber',
            'Marcar para cobrança em lote',
            'Copiar valor e descrição',
            'Ver memória de cálculo',
            'Editar (corrigir)',
            'Excluir lançamento',
        ], $this->rotulos($menu));

        // Itens que REUSAM a ação da linha: cada um diz qual.
        $acoes = $menu->filter('[data-obr-menu-acao]')->each(static fn (Crawler $i): string => (string) $i->attr('data-obr-menu-acao'));
        self::assertSame(['receber', 'marcar', 'copiar', 'memoria'], $acoes);
        self::assertSame('menuitemcheckbox', $menu->filter('[data-obr-menu-acao="marcar"]')->attr('role'));

        // Ícones do desenho.
        foreach (['receber' => 'bi-cash-coin', 'marcar' => 'bi-check2-square', 'copiar' => 'bi-clipboard', 'memoria' => 'bi-calculator'] as $acao => $icone) {
            self::assertCount(1, $menu->filter('[data-obr-menu-acao="' . $acao . '"] > i.' . $icone), "ícone de {$acao}");
        }

        // O separador fica antes do Excluir, como no desenho.
        self::assertCount(1, $menu->filter(':scope > .cob-obr-menu-sep + [data-bs-target="#modalExcluirObrigacao"]'));

        // Decisão do dono: os dois itens sem ação no sistema não entram.
        $texto = $menu->text();
        self::assertStringNotContainsString('Gerar boleto', $texto);
        self::assertStringNotContainsString('Enviar cobrança', $texto);

        // O que o "Copiar" leva: os três campos já formatados como a linha os mostra.
        $dados = json_decode((string) $menu->attr('data-obr-menu-dados'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('Taxa de condomínio 03/2026', $dados['descricao']);
        self::assertSame('10/03/2026', $dados['vencimento']);
        self::assertStringContainsString('R$', $dados['total']);
    }

    #[TestDox('Editar e Excluir continuam ligados aos modais, com os mesmos data-* de antes')]
    public function testEditarEExcluirContinuamLigadosAosModais(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        [$crawler, $id] = $this->abrirComUmaObrigacao($caso, $client);

        $menu = $crawler->filter('#menuObr' . $id);

        $editar = $menu->filter(':scope > [data-bs-target="#modalEditarObrigacao"]');
        self::assertCount(1, $editar);
        self::assertSame('modal', $editar->attr('data-bs-toggle'));
        self::assertSame('/cobrancas/obrigacoes/' . $id . '/editar', $editar->attr('data-acao-url'));
        self::assertSame('Taxa de condomínio 03/2026', $editar->attr('data-descricao'));
        self::assertSame('2026-03-10', $editar->attr('data-vencimento'));
        self::assertSame('120000', $editar->attr('data-valor-centavos'));
        foreach (['data-encargos-centavos', 'data-juros-centavos', 'data-multa-centavos', 'data-correcao-centavos',
            'data-tem-pagamento', 'data-dias', 'data-taxa-juros-bp', 'data-taxa-multa-bp', 'data-taxa-correcao-bp',
            'data-taxa-honorarios-bp'] as $atributo) {
            self::assertNotNull($editar->attr($atributo), "o Editar perdeu {$atributo}");
        }

        $excluir = $menu->filter(':scope > [data-bs-target="#modalExcluirObrigacao"]');
        self::assertCount(1, $excluir);
        self::assertSame('modal', $excluir->attr('data-bs-toggle'));
        self::assertSame('/cobrancas/obrigacoes/' . $id . '/excluir', $excluir->attr('data-acao-url'));
        self::assertNotEmpty($excluir->attr('data-token'));
        self::assertSame('Taxa de condomínio 03/2026', $excluir->attr('data-descricao'));
        self::assertStringContainsString('is-perigo', (string) $excluir->attr('class'));

        self::assertCount(1, $crawler->filter('#modalEditarObrigacao'));
        self::assertCount(1, $crawler->filter('#modalExcluirObrigacao'));
    }

    #[TestDox('Sem a capacidade financeira o menu não oferece Receber; o resto continua')]
    public function testSemCapacidadeFinanceiraOMenuNaoOfereceReceber(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorComCapacidades($client, ['resources.cobranca.gerenciar']);
        [, $caso] = $this->semearGrafo($tenant);
        [$crawler, $id] = $this->abrirComUmaObrigacao($caso, $client);

        self::assertSame([
            'Marcar para cobrança em lote',
            'Copiar valor e descrição',
            'Ver memória de cálculo',
            'Editar (corrigir)',
            'Excluir lançamento',
        ], $this->rotulos($crawler->filter('#menuObr' . $id)));
    }

    #[TestDox('Num caso encerrado o menu fica só com o que é leitura: copiar e ver a memória de cálculo')]
    public function testCasoEncerradoDeixaSoAsAcoesDeLeitura(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant, ['status' => StatusCaso::Encerrado]);
        [$crawler, $id] = $this->abrirComUmaObrigacao($caso, $client);

        $menu = $crawler->filter('#menuObr' . $id);
        self::assertSame(['Copiar valor e descrição', 'Ver memória de cálculo'], $this->rotulos($menu));
        self::assertCount(0, $menu->filter('[data-bs-target="#modalEditarObrigacao"], [data-bs-target="#modalExcluirObrigacao"]'));
        self::assertCount(0, $menu->filter('.cob-obr-menu-sep'), 'Sem Excluir, o separador não tem o que separar');
        // O ⋯ continua: copiar e ver a memória valem para quem só lê.
        self::assertCount(1, $crawler->filter('[data-obr-menu-abrir="menuObr' . $id . '"]'));
    }

    #[TestDox('Descrição hostil sai escapada no JSON do Copiar, no title e no título do menu')]
    public function testDescricaoHostilSaiEscapada(): void
    {
        $hostil = '"><script>alert(1)</script> \'aspas\' "duplas"';
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        [$crawler, $id] = $this->abrirComUmaObrigacao($caso, $client, $hostil);

        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('<script>alert(1)</script>', $html, 'A descrição vazou crua no HTML');
        self::assertCount(0, $crawler->filter('#secao-divida script'), 'Nenhum <script> nasce da descrição');

        $menu = $crawler->filter('#menuObr' . $id);
        self::assertCount(1, $menu, 'O atributo não foi quebrado pelas aspas');
        // Lido de volta pelo parser, cada lugar devolve o texto EXATO: escapado na ida, íntegro na volta.
        $dados = json_decode((string) $menu->attr('data-obr-menu-dados'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame($hostil, $dados['descricao']);
        $titulo = $menu->filter(':scope > .cob-obr-menu-titulo');
        self::assertSame($hostil, $titulo->text());
        self::assertSame($hostil, $titulo->attr('title'));
        self::assertSame($hostil, $menu->filter('[data-bs-target="#modalExcluirObrigacao"]')->attr('data-descricao'));
    }

    #[TestDox('O script do menu monta o DOM sem innerHTML e trata os dois gatilhos e o aviso')]
    public function testScriptDoMenuTrataOsDoisGatilhosSemInnerHtml(): void
    {
        $js = (string) file_get_contents(__DIR__ . '/../../../public/js/objeto-linha-menu.js');

        self::assertStringNotContainsString('innerHTML', $js);
        self::assertStringContainsString("addEventListener('contextmenu'", $js);
        self::assertStringContainsString('[data-obr-menu-abrir]', $js);
        self::assertStringContainsString('navigator.clipboard', $js);
        self::assertStringContainsString("aviso(BJ_T.t('cobranca_objeto.js.copiado'))", $js);
        self::assertSame('Copiado', CatalogoPtBr::carregar()['cobranca_objeto']['js']['copiado']);
        // Receber e Marcar reusam o botão e a checkbox da linha, não reimplementam.
        self::assertStringContainsString('[data-acao="receber"]', $js);
        self::assertStringContainsString('.jp-check', $js);
        self::assertStringContainsString('.cob-obr-chevron', $js);
        // Com o menu aberto, clique e botão direito fora caem numa camada que absorve e só fecha.
        self::assertStringContainsString('cob-obr-menu-camada', $js);
        self::assertStringContainsString('e.stopPropagation()', $js);
        // Setas só navegam com o foco dentro do menu.
        self::assertStringContainsString('aberto.menu.contains(document.activeElement)', $js);
        $css = (string) file_get_contents(__DIR__ . '/../../../public/css/objeto-linha-menu.css');
        self::assertMatchesRegularExpression('/\.cob-obr-menu-camada\s*\{[^}]*position:\s*fixed;[^}]*inset:\s*0;/', $css);
    }
}
