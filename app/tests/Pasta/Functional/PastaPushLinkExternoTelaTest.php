<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Tela interna do link externo do Push (spec push-link-externo §5 e §7, "Tela"): o menu da aba
 * Push, o item do menu ⋮, o modal `#modalLinkExternoPush` e o painel de acessos. O que se prova é
 * a marcação que o pasta-push-link.js lê (ids, classes, data-*), os textos do desenho e o arranjo
 * (filho direto). O clique e a aparência são do smoke do dono.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaPushLinkExternoTelaTest extends JusPrimeWebTestCase
{
    use CriaLinkExternoDoPushTrait;

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    private function abrirPastaComProcesso(): Crawler
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPush($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    #[TestDox('Aba Push: é a única com o gatilho do menu de contexto, apontando para #pushLinkMenu, e anuncia o Shift+F10')]
    public function testAbaPushTemOGatilhoDoMenu(): void
    {
        $crawler = $this->abrirPastaComProcesso();

        $aba = $crawler->filter('#pastaTabs > #push-tab[data-ppl-menu="pushLinkMenu"]');
        self::assertCount(1, $aba);
        self::assertSame('Shift+F10', $aba->attr('aria-keyshortcuts'));
        self::assertCount(1, $crawler->filter('[data-ppl-menu]'), 'só a aba Push abre este menu');
        self::assertStringStartsWith('Push Processual', (string) $aba->attr('title'), 'o title da aba não muda');
    }

    #[TestDox('Menu da aba: título "Push Processual", X de fechar e os quatro itens do desenho, todos escondidos até o JS decidir')]
    public function testMenuDaAba(): void
    {
        $crawler = $this->abrirPastaComProcesso();

        $menu = $crawler->filter('body #pushLinkMenu.ppl-menu[role="menu"][hidden]');
        self::assertCount(1, $menu);
        self::assertSame('Push Processual', trim($menu->filter('#pushLinkMenu > .ppl-menu-cab > .ppl-menu-titulo')->text()));
        self::assertCount(1, $menu->filter('#pushLinkMenu > .ppl-menu-cab > button.js-ppl-menu-fechar[aria-label="Fechar"]'));

        $itens = $menu->filter('#pushLinkMenu > button.ppl-menu-item[role="menuitem"][hidden]');
        self::assertSame(
            ['Copiar link externo ativo', 'Ver acessos ao link', 'Gerar link externo do Push', 'Link externo indisponível'],
            $itens->each(static fn (Crawler $b): string => trim($b->text())),
        );
        self::assertSame(
            ['bi bi-link-45deg', 'bi bi-eye', 'bi bi-broadcast', 'bi bi-lock'],
            $itens->each(static fn (Crawler $b): ?string => $b->filter('i')->attr('class')),
        );
        foreach (['js-ppl-menu-copiar', 'js-ppl-menu-acessos', 'js-ppl-menu-gerar', 'js-ppl-menu-indisponivel'] as $i => $classe) {
            self::assertStringContainsString($classe, (string) $itens->eq($i)->attr('class'));
        }
    }

    #[TestDox('Menu ⋮: "Link externo do Push" na seção Compartilhar, entre Copiar link da pasta e Imprimir resumo, com o selo de ativos')]
    public function testItemDoMenuDaPasta(): void
    {
        $crawler = $this->abrirPastaComProcesso();

        $item = $crawler->filter('#psMenuAcoes > button.ps-pop-item.js-ppl-abrir[aria-controls="modalLinkExternoPush"]');
        self::assertCount(1, $item);
        self::assertSame('Link externo do Push', trim($item->filter('span')->first()->text()));
        self::assertSame('bi bi-broadcast', $item->filter('i')->attr('class'));
        self::assertCount(1, $item->filter('button > .ppl-badge.js-ppl-badge-ativos[hidden]'), 'o selo nasce escondido; o JS o preenche');

        // Ordem do desenho ([EXP] L.1101-1105): Compartilhar · Copiar link · Link externo do Push · Imprimir resumo.
        $filhos = $crawler->filter('#psMenuAcoes > *')->each(static fn (Crawler $n): string => trim($n->text()));
        $secao = array_search('Compartilhar', $filhos, true);
        self::assertNotFalse($secao);
        self::assertSame('Copiar link da pasta', $filhos[$secao + 1]);
        self::assertStringStartsWith('Link externo do Push', $filhos[$secao + 2]);
        self::assertSame('Imprimir resumo', $filhos[$secao + 3]);
    }

    #[TestDox('Modal: cabeçalho com "Pasta N · Processo <nº>", URL da lista e um CSRF que a rota de gerar aceita (pasta_push_link_<id>)')]
    public function testModalCabecalhoEContrato(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPush($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        $modal = $crawler->filter('#modalLinkExternoPush.ppl-fundo[hidden]');
        self::assertCount(1, $modal);
        self::assertSame("/pasta/{$pasta->getId()}/push/links", $modal->attr('data-url-links'));
        self::assertSame('1', $modal->attr('data-tem-processo'));

        $janela = $modal->filter('#modalLinkExternoPush > .ppl-janela[role="dialog"][aria-modal="true"]');
        self::assertCount(1, $janela);
        self::assertSame('Link externo do Push', trim($janela->filter('.ppl-janela > .ppl-cab .ppl-cab-titulo')->text()));
        self::assertSame(
            'Pasta ' . $pasta->getNup() . ' · Processo 0701134-57.2025.8.07.0007',
            trim($janela->filter('.ppl-janela > .ppl-cab .ppl-cab-sub')->text()),
        );
        self::assertCount(1, $janela->filter('.ppl-janela > .ppl-cab > button.js-ppl-fechar[aria-label="Fechar"]'));

        // O token do data-csrf é o que o pasta-push-link.js manda em `_token`: a rota de gerar o aceita.
        $client->request('POST', (string) $modal->attr('data-url-links'), [
            '_token' => (string) $modal->attr('data-csrf'),
            'exigir_codigo' => '1',
            'mostrar_cliente' => '1',
            'incluir_analises' => '0',
        ]);
        self::assertResponseStatusCodeSame(201);
    }

    #[TestDox('Modal com processo: aviso fixo, faixa de 30 dias, "O que o terceiro vê" e os três toggles do desenho (código e cliente ligados, IA desligada e travada)')]
    public function testModalFormulario(): void
    {
        $crawler = $this->abrirPastaComProcesso();
        $corpo = $crawler->filter('#modalLinkExternoPush > .ppl-janela > .ppl-corpo');

        self::assertCount(0, $corpo->filter('.ppl-corpo > .ppl-sem-proc:not(.js-ppl-bloqueio)'));
        self::assertStringContainsString(
            'Quem receber o link vê somente o Push Processual deste processo, sem login e sem poder alterar nada. Documentos, financeiro, metas e registros internos da pasta não aparecem.',
            $corpo->filter('.ppl-corpo > .ppl-aviso')->text(),
        );
        self::assertSame('somente o Push Processual', $corpo->filter('.ppl-corpo > .ppl-aviso strong')->text());

        $form = $corpo->filter('.ppl-corpo > .js-ppl-form');
        self::assertCount(1, $form);
        self::assertStringStartsWith('Válido por 30 dias.', trim($form->filter('.js-ppl-form > .ppl-faixa')->text()));
        self::assertSame('O que o terceiro vê', $form->filter('.js-ppl-form > .ppl-ve > .ppl-rotulo')->text());
        $fixo = $form->filter('.ppl-ve > .ppl-ve-fixo');
        self::assertStringContainsString('Movimentações oficiais do processo', $fixo->text());
        self::assertSame('1 hoje · atualiza sozinho', trim($fixo->filter('.ppl-ve-fixo-n')->text()), 'a contagem vem do Push da pasta (uma publicação)');

        $ops = $form->filter('.ppl-ve > button.js-ppl-op[role="switch"]');
        self::assertSame(['exigir_codigo', 'mostrar_cliente', 'incluir_analises'], $ops->each(static fn (Crawler $o): ?string => $o->attr('data-op')));
        self::assertSame(['true', 'true', 'false'], $ops->each(static fn (Crawler $o): ?string => $o->attr('aria-checked')));
        self::assertSame(
            ['Exigir código de acesso', 'Mostrar o nome do cliente', 'Incluir Análises IA'],
            $ops->each(static fn (Crawler $o): string => $o->filter('.ppl-op-titulo')->text()),
        );
        self::assertSame(
            ['Gera um código de 6 dígitos para enviar separado do link.', 'Sem isso, o link mostra só o número do processo.', 'Esta pasta ainda não tem Análise IA.'],
            $ops->each(static fn (Crawler $o): string => $o->filter('.ppl-op-desc')->text()),
        );
        self::assertNotNull($ops->eq(2)->attr('disabled'), 'a IA nasce travada até o servidor contar as análises compartilháveis');
        self::assertCount(3, $ops->filter('button > .ppl-sw > .ppl-sw-bola'));

        // Estado criado: escondido, com o input do link, Copiar, código, validade, Ver como terceiro e Criar outro.
        $criado = $corpo->filter('.ppl-corpo > .js-ppl-criado[hidden]');
        self::assertCount(1, $criado);
        self::assertCount(1, $criado->filter('.ppl-criado-linha > input.js-ppl-criado-url[readonly]'));
        self::assertCount(1, $criado->filter('.ppl-criado-linha > button.js-ppl-criado-copiar'));
        self::assertStringContainsString('Envie por outro canal, separado do link.', $criado->filter('.js-ppl-criado-codigo-linha')->text());
        self::assertSame('Ver como terceiro', trim($criado->filter('.ppl-criado-rodape > .js-ppl-ver-terceiro')->text()));
        self::assertSame('Criar outro', trim($criado->filter('.ppl-criado-rodape > .js-ppl-criar-outro')->text()));
        // D3: um link só, já ativo. Nada do link completo nem do selo do domínio.
        $texto = $crawler->filter('#modalLinkExternoPush')->text();
        foreach (['Copiar link completo', 'ativa com o domínio', 'Link completo:', 'Link fácil', 'DICA'] as $fora) {
            self::assertStringNotContainsString($fora, $texto);
        }

        // Rodapé: Fechar e Gerar e copiar link.
        $rodape = $crawler->filter('#modalLinkExternoPush > .ppl-janela > .ppl-rodape-janela');
        self::assertSame(['Fechar', 'Gerar e copiar link'], $rodape->filter('.ppl-rodape-janela > button')->each(static fn (Crawler $b): string => trim($b->text())));
        self::assertCount(1, $rodape->filter('.ppl-rodape-janela > button.js-ppl-gerar'));

        // Lista "Links desta pasta": cabeçalho e o botão do painel de acessos.
        $lista = $corpo->filter('.ppl-corpo > .js-ppl-lista-bloco[hidden]');
        self::assertSame('Links desta pasta', $lista->filter('.ppl-lista-cab > .ppl-rotulo')->text());
        self::assertCount(1, $lista->filter('.ppl-lista-cab > button.js-ppl-abrir-acessos[title="Abrir o painel de acessos"]'));
        self::assertCount(1, $lista->filter('.js-ppl-lista-bloco > ul.js-ppl-lista'));
    }

    #[TestDox('Pasta sem processo: o modal mostra o aviso do desenho, sem formulário e sem "Gerar e copiar link"')]
    public function testPastaSemProcesso(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        $modal = $crawler->filter('#modalLinkExternoPush');
        self::assertSame('0', $modal->attr('data-tem-processo'));
        self::assertSame('Pasta ' . $pasta->getNup(), trim($modal->filter('.ppl-cab-sub')->text()));
        self::assertSame(
            'Esta pasta não tem processo vinculado. Vincule um processo para compartilhar o Push.',
            trim($modal->filter('#modalLinkExternoPush > .ppl-janela > .ppl-corpo > .ppl-sem-proc')->text()),
        );
        self::assertCount(0, $modal->filter('.js-ppl-form'));
        self::assertCount(0, $modal->filter('.ppl-aviso'));
        self::assertCount(0, $modal->filter('.js-ppl-gerar'));
        self::assertCount(1, $modal->filter('.ppl-rodape-janela > .js-ppl-fechar'));
        self::assertCount(1, $modal->filter('.ppl-corpo > .js-ppl-lista-bloco'), 'links antigos continuam visíveis e revogáveis');
        // O menu da aba e o item do ⋮ continuam: o JS mostra "Link externo indisponível" com o motivo do servidor.
        self::assertCount(1, $crawler->filter('#pastaTabs > #push-tab[data-ppl-menu="pushLinkMenu"]'));
        self::assertCount(1, $crawler->filter('#psMenuAcoes > .js-ppl-abrir'));
    }

    #[TestDox('Painel "Acessos ao link do Push": cabeçalho, total, lista e o rodapé, escondido')]
    public function testPainelDeAcessos(): void
    {
        $crawler = $this->abrirPastaComProcesso();

        $painel = $crawler->filter('#pushLinkAcessos.ppl-ac-fundo[hidden] > .ppl-ac-janela[role="dialog"]');
        self::assertCount(1, $painel);
        self::assertSame('Acessos ao link do Push', $painel->filter('.ppl-ac-janela > .ppl-ac-cab .ppl-ac-cab-titulo')->text());
        self::assertCount(1, $painel->filter('.ppl-ac-janela > .ppl-ac-cab > button.js-ppl-ac-fechar[aria-label="Fechar"]'));
        self::assertSame('acessos no total', $painel->filter('.ppl-ac-janela > .ppl-ac-resumo > .js-ppl-ac-n-txt')->text());
        self::assertCount(1, $painel->filter('.ppl-ac-janela > .js-ppl-ac-lista'));
        self::assertStringStartsWith('Conta cada abertura do link', $painel->filter('.ppl-ac-janela > .ppl-ac-rodape')->text());
    }

    #[TestDox('A tela carrega o CSS e o JS do link externo, o JS depois do pasta-dialogos.js')]
    public function testCarregaOsAssets(): void
    {
        $crawler = $this->abrirPastaComProcesso();

        self::assertCount(1, $crawler->filter('link[rel="stylesheet"][href*="css/pasta-push-link.css"]'));
        $scripts = $crawler->filter('script[src]')->each(static fn (Crawler $s): string => (string) $s->attr('src'));
        $dialogos = $this->posicao($scripts, 'js/pasta-dialogos.js');
        $link = $this->posicao($scripts, 'js/pasta-push-link.js');
        self::assertGreaterThan($dialogos, $link, 'confirmarPs/avisarPs já existem quando o script do link roda');
    }

    /** @param list<string> $scripts */
    private function posicao(array $scripts, string $trecho): int
    {
        foreach ($scripts as $i => $src) {
            if (str_contains($src, $trecho)) {
                return $i;
            }
        }
        self::fail("script {$trecho} não carregado");
    }
}
