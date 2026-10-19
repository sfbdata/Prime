<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Tela da pasta com abas ocultas (spec pasta-ocultar-aba §5): para quem está oculto, nem a aba nem o
 * painel existem; para quem configura, a aba fica com o ícone da regra e há o menu e o modal. O clique
 * e a aparência são do smoke do dono.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaAbaOcultaTelaTest extends JusPrimeWebTestCase
{
    use CriaCenarioDeAbaOcultaTrait;

    private Tenant $tenant;
    private User $primario;
    private User $ana;
    private Pasta $pasta;

    private function cenario(): void
    {
        $this->tenant = $this->criarTenant();
        $this->primario = $this->criarPrimario($this->tenant);
        $this->ana = $this->criarColaborador($this->tenant);
        [$this->pasta] = $this->criarPastaComPush($this->tenant);
    }

    private function abrirComo(User $quem): Crawler
    {
        $client = static::getClient() ?? $this->cliente();
        $this->logarComTenant($client, $quem, $this->tenant);
        $crawler = $client->request('GET', "/pasta/{$this->pasta->getId()}");
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    #[TestDox('Push oculto: sem #push-tab, sem #push, sem o menu e o modal do link e sem o item "Link externo do Push" do ⋮')]
    public function testPushOculto(): void
    {
        $this->cliente();
        $this->cenario();
        $this->ocultarAba($this->pasta, AbaDaPasta::Push, $this->primario, [$this->ana]);

        $crawler = $this->abrirComo($this->ana);

        self::assertCount(0, $crawler->filter('#push-tab'));
        self::assertCount(0, $crawler->filter('#push'));
        self::assertCount(0, $crawler->filter('#pushLinkMenu'));
        self::assertCount(0, $crawler->filter('#modalLinkExternoPush'));
        self::assertCount(0, $crawler->filter('.js-ppl-abrir'));
        self::assertCount(1, $crawler->filter('#pastaTabs > #dados-tab.active'), 'as outras abas seguem');
        self::assertCount(0, $crawler->filter('.ps-aba-oculta-ind'), 'quem é oculto não vê a regra');
        self::assertSame('0', $crawler->filter('#paoRaiz')->attr('data-pode-configurar'));
        self::assertCount(0, $crawler->filter('#abaOcultaMenu'));
        self::assertCount(0, $crawler->filter('#paoModal'));
    }

    #[TestDox('Financeiro oculto para toda a equipe: sem #financeiro-tab nem #financeiro (nem o modal de pagamento)')]
    public function testFinanceiroOculto(): void
    {
        $this->cliente();
        $this->cenario();
        $this->ocultarAba($this->pasta, AbaDaPasta::Financeiro, $this->primario);

        $crawler = $this->abrirComo($this->ana);

        self::assertCount(0, $crawler->filter('#financeiro-tab'));
        self::assertCount(0, $crawler->filter('#financeiro'));
        self::assertCount(0, $crawler->filter('#modalNovoPagamento'));
        self::assertCount(1, $crawler->filter('#pastaTabs > #push-tab'));
    }

    #[TestDox('Dados oculto: a primeira visível (Metas) nasce ativa, aba e painel')]
    public function testDadosOcultoAtivaAPrimeiraVisivel(): void
    {
        $this->cliente();
        $this->cenario();
        $this->ocultarAba($this->pasta, AbaDaPasta::Dados, $this->primario);

        $crawler = $this->abrirComo($this->ana);

        self::assertCount(0, $crawler->filter('#dados-tab'));
        self::assertCount(0, $crawler->filter('#dados'));
        self::assertCount(1, $crawler->filter('#pastaTabs > #tarefas-tab.active[aria-selected="true"]'));
        self::assertCount(1, $crawler->filter('#pastaTabsContent > #tarefas.tab-pane.show.active'));
        self::assertCount(1, $crawler->filter('#pastaTabsContent > .tab-pane.active'), 'só um painel ativo');
    }

    #[TestDox('Todas as abas ocultas: aviso "Nenhuma aba disponível para você nesta pasta."')]
    public function testNenhumaAba(): void
    {
        $this->cliente();
        $this->cenario();
        foreach (AbaDaPasta::cases() as $aba) {
            $this->ocultarAba($this->pasta, $aba, $this->primario);
        }

        $crawler = $this->abrirComo($this->ana);

        self::assertCount(0, $crawler->filter('#pastaTabs > .ps-aba'));
        self::assertCount(0, $crawler->filter('#pastaTabsContent > .tab-pane'));
        self::assertSame('Nenhuma aba disponível para você nesta pasta.', trim($crawler->filter('#pastaTabsContent > .ps-abas-nenhuma')->text()));
    }

    #[TestDox('Primário: vê a aba oculta, com o ícone bi-eye-slash e o title do desenho, e o menu e o modal do "Ocultar aba"')]
    public function testPrimarioVeOIcone(): void
    {
        $this->cliente();
        $this->cenario();
        $this->ocultarAba($this->pasta, AbaDaPasta::Push, $this->primario, [$this->ana]);
        $this->ocultarAba($this->pasta, AbaDaPasta::Financeiro, $this->primario);

        $crawler = $this->abrirComo($this->primario);

        $push = $crawler->filter('#pastaTabs > #push-tab');
        self::assertCount(1, $push);
        self::assertCount(1, $crawler->filter('#push'));
        self::assertSame('Oculta para 1 pessoa(s)', $crawler->filter('#push-tab > i.bi.bi-eye-slash.ps-aba-oculta-ind')->attr('title'));
        self::assertSame('Oculta para toda a equipe', $crawler->filter('#financeiro-tab > i.bi-eye-slash.ps-aba-oculta-ind')->attr('title'));
        self::assertCount(0, $crawler->filter('#dados-tab > .ps-aba-oculta-ind'), 'sem regra, sem ícone');
        self::assertSame('1', $push->attr('data-pao-regra'));
        self::assertSame(' · oculta para 1', $push->attr('data-pao-sufixo'));
        self::assertSame('Push Processual', $push->attr('data-pao-rotulo'));
        self::assertSame(' · oculta para 1', $crawler->filter('#financeiro-tab')->attr('data-pao-sufixo'), 'toda a equipe: N = elegíveis (desenho L.4412)');
        self::assertNull($crawler->filter('#dados-tab')->attr('data-pao-regra'));

        $raiz = $crawler->filter('#paoRaiz');
        self::assertSame('1', $raiz->attr('data-pode-configurar'));
        self::assertSame("/pasta/{$this->pasta->getId()}/abas/__ABA__/equipe", $raiz->attr('data-url-equipe'));
        self::assertSame("/pasta/{$this->pasta->getId()}/abas/__ABA__/ocultar-para", $raiz->attr('data-url-ocultar-para'));
    }

    #[TestDox('Menu do botão direito (quem configura): itens e ícones do desenho; "Mostrar para todos" nasce escondido')]
    public function testMenuDoPrimario(): void
    {
        $this->cliente();
        $this->cenario();

        $crawler = $this->abrirComo($this->primario);

        $itens = $crawler->filter('#abaOcultaMenu.ppl-menu[role="menu"][hidden] > button.ppl-menu-item[role="menuitem"]');
        self::assertSame(
            ['Ocultar para toda a equipe', 'Ocultar para…', 'Mostrar para todos'],
            $itens->each(static fn (Crawler $b): string => trim($b->text())),
        );
        self::assertSame(
            ['bi bi-eye-slash', 'bi bi-people', 'bi bi-eye'],
            $itens->each(static fn (Crawler $b): ?string => $b->filter('i')->attr('class')),
        );
        self::assertNotNull($itens->eq(2)->attr('hidden'));
        self::assertCount(1, $crawler->filter('#abaOcultaMenu > .ppl-menu-cab > #abaOcultaMenuTitulo.ppl-menu-titulo'));
    }

    #[TestDox('Modal "Ocultar aba": cabeçalho, linha "Toda a equipe", lista, Cancelar e Salvar, e o modelo da linha com a etiqueta OCULTA')]
    public function testModal(): void
    {
        $this->cliente();
        $this->cenario();

        $crawler = $this->abrirComo($this->primario);

        $janela = $crawler->filter('#paoModal.pao-fundo[hidden] > .pao-janela[role="dialog"][aria-modal="true"]');
        self::assertCount(1, $janela);
        self::assertSame('Quem estiver marcado deixa de ver esta aba', trim($janela->filter('.pao-janela > .pao-cab > .pao-cab-textos > .pao-cab-sub')->text()));
        self::assertCount(1, $janela->filter('.pao-janela > .pao-cab > i.bi-eye-slash'));
        self::assertCount(1, $janela->filter('.pao-janela > button.pao-todos.js-pao-todos[role="checkbox"]'));
        self::assertCount(1, $janela->filter('.pao-janela > .pao-lista.js-pao-lista'));
        self::assertSame(['Cancelar', 'Salvar'], $janela->filter('.pao-janela > .pao-rodape > button')->each(static fn (Crawler $b): string => trim($b->text())));

        $modelo = (string) $crawler->filter('template#paoLinhaModelo')->html();
        self::assertStringContainsString('class="pao-linha"', $modelo);
        self::assertStringContainsString('class="pao-linha-nome"', $modelo);
        self::assertStringContainsString('class="pao-linha-funcao"', $modelo);
        self::assertMatchesRegularExpression('#<span class="pao-selo" hidden>OCULTA</span>#', $modelo);
    }

    #[TestDox('Para todos, as abas levam o rótulo para o botão direito (o aviso de quem não configura sai do JS)')]
    public function testAbasTemORotulo(): void
    {
        $this->cliente();
        $this->cenario();

        $crawler = $this->abrirComo($this->ana);

        self::assertSame(
            ['dados', 'tarefas', 'processo', 'financeiro', 'detalhes', 'documentos', 'push'],
            $crawler->filter('#pastaTabs > .ps-aba')->each(static fn (Crawler $b): ?string => $b->attr('data-pao-aba')),
        );
    }

    #[TestDox('Financeiro oculto: a pendência de pagamentos some do resumo impresso; quem configura ainda a vê')]
    public function testFinanceiroOcultoSomePendencia(): void
    {
        $client = $this->cliente();
        $this->cenario();
        $this->pasta->setSituacaoContrato('PENDENTE');
        $this->em()->flush();
        $this->ocultarAba($this->pasta, AbaDaPasta::Financeiro, $this->primario, [$this->ana]);

        $this->logarComTenant($client, $this->ana, $this->tenant);
        $client->request('GET', "/pasta/{$this->pasta->getId()}/resumo/imprimir?secao=resumo");
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('pendente de assinatura', (string) $client->getResponse()->getContent());

        $this->logarComTenant($client, $this->primario, $this->tenant);
        $client->request('GET', "/pasta/{$this->pasta->getId()}/resumo/imprimir?secao=resumo");
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('pendente de assinatura', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Push oculto: o resumo impresso sai sem "Últimas movimentações"')]
    public function testPushOcultoSomeDoResumo(): void
    {
        $client = $this->cliente();
        $this->cenario();
        $this->ocultarAba($this->pasta, AbaDaPasta::Push, $this->primario);

        $this->logarComTenant($client, $this->primario, $this->tenant);
        $client->request('GET', "/pasta/{$this->pasta->getId()}/resumo/imprimir?secao=resumo");
        self::assertStringContainsString('data-secao="movimentacoes"', (string) $client->getResponse()->getContent(), 'quem configura vê as movimentações');

        $this->logarComTenant($client, $this->ana, $this->tenant);
        $client->request('GET', "/pasta/{$this->pasta->getId()}/resumo/imprimir?secao=resumo");
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('data-secao="movimentacoes"', (string) $client->getResponse()->getContent());
    }
}
