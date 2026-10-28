<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Functional;

use App\Controller\PastaController;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * O botão "BlueJus Intelligence" no cabeçalho da pasta e o drawer da BlueJus IA (specs
 * inteligencia-agentes-da-pasta §5 e inteligencia-drawer-da-pasta F1/F2/F3). O botão nunca finge:
 * carrega o motivo REAL da `Disponibilidade`; dentro do drawer, as abas Análise, Documentos,
 * Relatórios e Perguntar do desenho, e cada comando/relatório mostra "Gerar análise" (ou "Gerar
 * <relatório>") habilitado ou o motivo, desabilitado. Documentos (F4) some para quem tem a aba
 * Documentos da pasta oculta. Sem `modules.inteligencia.view`, nada de IA no cabeçalho.
 *
 * Arranjo com combinador de FILHO DIRETO (`.ps-cabecalho > .ps-cab-cliente > .ps-cab-ia`): "existe
 * na página" seria verdade com o botão no lugar errado. Estilo e JS não são visíveis aqui.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaCabecalhoIaTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;

    private const BOTAO = '.ps-page > .ps-cabecalho > .ps-cab-cliente > button.ps-cab-ia';
    private const DRAWER = 'aside.ia-drawer#psIaDrawer[data-ia-drawer]';
    private const ANALISE = self::DRAWER . ' > .ia-drawer-corpo > .ia-agentes[data-ia-painel] > .ia-aba-painel[data-ia-aba-painel="analise"]';
    private const RELATORIOS = self::DRAWER . ' > .ia-drawer-corpo > .ia-agentes[data-ia-painel] > .ia-aba-painel[data-ia-aba-painel="relatorios"]';
    private const DOCUMENTOS = self::DRAWER . ' > .ia-drawer-corpo > .ia-agentes[data-ia-painel] > .ia-aba-painel[data-ia-aba-painel="documentos"]';

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    private function abrirPasta(KernelBrowser $client, int $pastaId): Crawler
    {
        $crawler = $client->request('GET', "/pasta/{$pastaId}");
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    #[TestDox('Sem provedor: botão à direita da linha do cliente, com o motivo real; o drawer existe (oculto) com os 15 comandos e relatórios e o Índice travados')]
    public function testSemProvedor(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->provedorFalso()->desconfigurar();
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrirPasta($client, (int) $pasta->getId());

        $botao = $crawler->filter(self::BOTAO);
        self::assertSame(1, $botao->count(), 'o botão mora na linha do cliente do cabeçalho (filho direto)');
        self::assertSame('0', $botao->attr('data-ia-disponivel'));
        self::assertSame('nao_configurada_na_plataforma', $botao->attr('data-ia-motivo'));
        self::assertSame('IA não configurada nesta instalação', $botao->attr('title'));
        self::assertSame('psIaDrawer', $botao->attr('aria-controls'));
        self::assertSame('1', $botao->attr('data-bjia'));
        self::assertSame('0', $botao->attr('data-mini'), 'nasce expandido; o JS recolhe com o painel aberto');
        self::assertSame('Abrir BlueJus IA', $botao->attr('aria-label'));
        self::assertSame('BlueJus', trim($botao->filter('button > .ps-cab-ia-nomew')->text()), 'letras uma a uma, filhas diretas do nome');
        self::assertSame(7, $botao->filter('.ps-cab-ia-nomew > .ps-cab-ia-letra')->count());
        self::assertSame(1, $botao->filter('button > .ps-cab-ia-icone > img.ps-cab-ia-logo[src$="bluejus-simbolo-petroleo.png"]')->count());
        self::assertSame(1, $botao->filter('button > .ps-cab-ia-icone > .ps-cab-ia-ia > svg')->count(), 'ícone IA que entra depois do símbolo');
        self::assertStringNotContainsString('Intelligence', $botao->text(), 'o rótulo antigo saiu');

        $drawer = $crawler->filter(self::DRAWER);
        self::assertSame(1, $drawer->count());
        self::assertNotNull($drawer->attr('hidden'), 'nasce fechado');
        self::assertSame("/pasta/{$pasta->getId()}/ia/agentes", $drawer->attr('data-ia-painel-url'));
        self::assertNotEmpty($drawer->attr('data-ia-token'));
        self::assertStringContainsString('IA não configurada nesta instalação', $drawer->filter('.ia-drawer-cab > .ia-drawer-motivo')->text());
        self::assertSame(0, $drawer->filter('.ia-agente')->count(), 'os cartões de agente deram lugar aos comandos');
        self::assertSame(10, $crawler->filter(self::ANALISE . ' > .ia-comando[data-ia-comando]')->count());
        self::assertSame(5, $crawler->filter(self::RELATORIOS . ' > .ia-comando[data-ia-comando]')->count());
        $ctas = $drawer->filter('.ia-comando > button.ia-cta[data-ia-gerar], .ia-docs-acoes > button.ia-cta[data-ia-gerar]');
        self::assertSame(17, $ctas->count(), '10 comandos, 5 relatórios e os dois botões da aba Documentos ("Ler" e "Índice inteligente", PR F)');
        $ctas->each(static function (Crawler $cta): void {
            self::assertNotNull($cta->attr('disabled'), 'sem provedor nenhum comando é clicável');
            self::assertSame('0', $cta->attr('data-ia-disponivel'));
            self::assertSame('nao_configurada_na_plataforma', $cta->attr('data-ia-motivo'));
            self::assertStringContainsString('IA não configurada nesta instalação', $cta->text());
            self::assertNull($cta->attr('data-ia-url'), 'desabilitado não carrega a rota do pedido');
        });
        self::assertSame(1, $crawler->filter('link[href*="css/pasta-ia-agentes.css"]')->count());
        self::assertSame(1, $crawler->filter('script[src*="js/pasta-ia-agentes.js"]')->count());
    }

    #[TestDox('Disponível: botão habilitado e cada comando com "Gerar análise", o agente e o que lê')]
    public function testDisponivel(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrirPasta($client, (int) $pasta->getId());

        $botao = $crawler->filter(self::BOTAO);
        self::assertSame('1', $botao->attr('data-ia-disponivel'));
        self::assertSame('disponivel', $botao->attr('data-ia-motivo'));
        self::assertStringContainsString('BlueJus IA', (string) $botao->attr('title'));

        $drawer = $crawler->filter(self::DRAWER);
        self::assertSame(0, $drawer->filter('.ia-drawer-motivo')->count(), 'disponível não tem faixa de motivo');
        $analisar = $crawler->filter(self::ANALISE . ' > .ia-comando[data-ia-comando="analisar_pasta"]');
        self::assertSame('Analisar esta pasta', trim($analisar->filter('.ia-agente-cab > .ia-agente-txt > .ia-agente-nome')->text()));
        self::assertStringContainsString('Agente Gestor. Lê: dados da pasta, processos, clientes', $analisar->filter('.ia-agente-le')->text());
        self::assertStringContainsString('financeiro', $analisar->filter('.ia-agente-le')->text());
        $cta = $analisar->filter('button.ia-cta[data-ia-gerar]');
        self::assertNull($cta->attr('disabled'));
        self::assertSame('1', $cta->attr('data-ia-disponivel'));
        self::assertSame("/pasta/{$pasta->getId()}/ia/comandos/analisar_pasta/analises", $cta->attr('data-ia-url'));
        self::assertStringContainsString('Gerar análise', $cta->text());
        // O painel ainda não foi carregado (é o JS que busca ao abrir): a lista diz "carregando".
        self::assertSame('0', $drawer->filter('.ia-agentes')->attr('data-ia-carregado'));
        self::assertSame(1, $analisar->filter('.ia-ag-lista[data-ia-lista="analisar_pasta"] > [data-ia-carregando]')->count());
        self::assertSame("/pasta/{$pasta->getId()}/ia/comandos/analisar_pasta/analises", $analisar->filter('.ia-ag-lista')->attr('data-ia-url'));
        self::assertStringNotContainsString('financeiro', $drawer->filter('.ia-comando[data-ia-comando="processo_parado"] .ia-agente-le')->text());

        // Chips do contexto desta tela (IA-005): pasta sem cliente nem responsável.
        $chips = $drawer->filter('.ia-drawer-cab > .ia-drawer-chips > .ia-chip');
        self::assertSame(4, $chips->count());
        self::assertStringContainsString('Pasta ' . $pasta->getNup(), $chips->eq(0)->text());
        self::assertSame('Sem cliente', trim($chips->eq(1)->text()));
        self::assertSame('0701134-57.2025.8.07.0007', trim($chips->eq(2)->text()));
        self::assertSame('Sem responsável', trim($chips->eq(3)->text()));
    }

    #[TestDox('IA-005: chips do desenho na ordem: Pasta nº · cliente principal · nº do processo principal · responsável')]
    public function testChipsDoContexto(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->criarClientePF($tenant, $pasta, 'Maria <i>Souza</i>');
        $responsavel = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view'], 'resp');
        $responsavel->setFullName('Dra. Ana Lima');
        $pasta->setResponsavel($responsavel);
        $this->em()->flush();
        $this->ligarIaNoTenant($tenant, $user);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrirPasta($client, (int) $pasta->getId());

        $chips = $crawler->filter(self::DRAWER . ' > .ia-drawer-cab > .ia-drawer-chips > .ia-chip');
        self::assertSame(
            ['pasta', 'cliente', 'processo', 'responsavel'],
            $chips->each(static fn (Crawler $c): ?string => $c->attr('data-ia-chip')),
            'os quatro chips do desenho, nesta ordem',
        );
        self::assertSame('Pasta ' . $pasta->getNup(), trim($chips->eq(0)->text()));
        self::assertSame(mb_strtoupper('Maria <i>Souza</i>'), mb_strtoupper(trim($chips->eq(1)->text())), 'o nome do cliente sai como texto');
        self::assertSame(0, $chips->eq(1)->filter('i:not(.bi)')->count(), 'nenhuma tag do nome vira HTML');
        self::assertSame('0701134-57.2025.8.07.0007', trim($chips->eq(2)->text()));
        self::assertSame('Dra. Ana Lima', trim($chips->eq(3)->text()));
        self::assertSame(1, $chips->eq(0)->filter('i.bi-folder2-open')->count());
        self::assertSame(1, $chips->eq(1)->filter('i.bi-person')->count());
        self::assertSame(1, $chips->eq(2)->filter('i.bi-diagram-3')->count());
        self::assertSame(1, $chips->eq(3)->filter('i.bi-person-badge')->count());
    }

    #[TestDox('Escritório com a IA desligada: botão e agentes com "desligada neste escritório"')]
    public function testTenantDesligado(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->liberarIaSemLigarNoTenant($tenant, $user);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrirPasta($client, (int) $pasta->getId());

        self::assertSame('desligada_no_escritorio', $crawler->filter(self::BOTAO)->attr('data-ia-motivo'));
        self::assertStringContainsString('desligada neste escritório', (string) $crawler->filter(self::BOTAO)->attr('title'));
        self::assertStringContainsString('desligada neste escritório', $crawler->filter(self::DRAWER . ' .ia-comando[data-ia-comando="analisar_historico"] .ia-cta')->text());
        self::assertStringContainsString('desligada neste escritório. Peça ao administrador', (string) $crawler->filter(self::BOTAO)->attr('title'));
        self::assertStringNotContainsString('—', (string) $crawler->filter(self::BOTAO)->attr('title'));
    }

    #[TestDox('Sem modules.inteligencia.view: nem botão, nem drawer, nem script no cabeçalho')]
    public function testSemPermissaoNadaDeIa(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $admin);
        $user = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view']);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrirPasta($client, (int) $pasta->getId());

        self::assertSame(1, $crawler->filter('.ps-cabecalho > .ps-cab-cliente')->count(), 'o cabeçalho continua lá');
        self::assertSame(0, $crawler->filter('.ps-cab-ia')->count());
        self::assertSame(0, $crawler->filter('.ia-drawer')->count());
        self::assertSame(0, $crawler->filter('script[src*="pasta-ia-agentes.js"]')->count());
        self::assertStringNotContainsString('Agente Gestor', (string) $client->getResponse()->getContent());
    }

    // =========================================================================
    // Abas, comandos e relatórios (spec inteligencia-drawer-da-pasta, F1/F2)
    // =========================================================================

    #[TestDox('Abas do desenho no cabeçalho do drawer (filho direto), na ordem: Análise, Documentos, Relatórios e Perguntar, com ícones')]
    public function testAbas(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrirPasta($client, (int) $pasta->getId());

        $abas = $crawler->filter(self::DRAWER . ' > .ia-drawer-cab > .ia-abas[role="tablist"] > button.ia-aba[role="tab"]');
        self::assertSame(4, $abas->count());
        self::assertSame(['Análise', 'Documentos', 'Relatórios', 'Perguntar'], $abas->each(static fn (Crawler $a): string => trim($a->text())));
        self::assertSame(['analise', 'documentos', 'relatorios', 'perguntar'], $abas->each(static fn (Crawler $a): ?string => $a->attr('data-ia-aba')));
        self::assertSame(1, $abas->eq(0)->filter('i.bi-activity')->count());
        self::assertSame(1, $abas->eq(1)->filter('i.bi.bi-file-earmark-text')->count());
        self::assertSame(1, $abas->eq(2)->filter('i.bi-bar-chart-line')->count());
        self::assertSame(1, $abas->eq(3)->filter('i.bi-chat-dots')->count());
        self::assertSame('true', $abas->eq(0)->attr('aria-selected'));
        self::assertSame('false', $abas->eq(1)->attr('aria-selected'));
        self::assertSame('false', $abas->eq(2)->attr('aria-selected'));
        self::assertSame('false', $abas->eq(3)->attr('aria-selected'));
        self::assertSame('ia-aba-analise', $abas->eq(0)->attr('aria-controls'));
        self::assertSame('ia-aba-documentos', $abas->eq(1)->attr('aria-controls'));
        self::assertSame('ia-aba-relatorios', $abas->eq(2)->attr('aria-controls'));
        self::assertSame('ia-aba-perguntar', $abas->eq(3)->attr('aria-controls'));

        // Os painéis das abas, filhos diretos do painel; Relatórios nasce oculto.
        self::assertSame(1, $crawler->filter(self::ANALISE . '#ia-aba-analise[role="tabpanel"]')->count());
        self::assertNull($crawler->filter(self::ANALISE)->attr('hidden'));
        self::assertSame(1, $crawler->filter(self::RELATORIOS . '#ia-aba-relatorios[role="tabpanel"]')->count());
        self::assertNotNull($crawler->filter(self::RELATORIOS)->attr('hidden'));
        self::assertSame(1, $crawler->filter(self::DOCUMENTOS . '#ia-aba-documentos[role="tabpanel"]')->count());
        self::assertNotNull($crawler->filter(self::DOCUMENTOS)->attr('hidden'));
        // Os painéis na ordem do desenho: Análise, Documentos, Relatórios.
        self::assertSame(
            ['analise', 'documentos', 'relatorios'],
            $crawler->filter(self::DRAWER . ' > .ia-drawer-corpo > .ia-agentes[data-ia-painel] > .ia-aba-painel')->each(static fn (Crawler $p): ?string => $p->attr('data-ia-aba-painel')),
        );

        $html = (string) $client->getResponse()->getContent();
        $drawer = $crawler->filter(self::DRAWER);
        self::assertSame(0, $drawer->filter('[data-ia-aba="docs"], [data-ia-aba="chat"]')->count());
        self::assertStringNotContainsString('Relatório do cliente', $html, 'D12: espera a decisão do dono');
    }

    #[TestDox('Aba Análise: "Comandos para esta pasta" com os 10 comandos do desenho em grade (ícone + rótulo), cada um com a sua seção')]
    public function testComandosDaAbaAnalise(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrirPasta($client, (int) $pasta->getId());

        self::assertSame('Comandos para esta pasta', trim($crawler->filter(self::ANALISE . ' > p.ia-secao-titulo')->text()));
        $botoes = $crawler->filter(self::ANALISE . ' > .ia-comandos > button.ia-comando-btn[data-ia-escolher]');
        self::assertSame([
            'Analisar esta pasta',
            'Identificar riscos',
            'Verificar prazos',
            'Processo parado?',
            'Próximas providências',
            'Briefing para o advogado',
            'Linha do tempo',
            'Analisar histórico',
            'Raio-X jurídico',
            'Análise jurídica profunda',
        ], $botoes->each(static fn (Crawler $b): string => trim($b->filter('.ia-comando-rotulo')->text())));
        self::assertSame(
            ['bi-clipboard-data', 'bi-shield-exclamation', 'bi-alarm', 'bi-hourglass-split', 'bi-signpost-2', 'bi-person-video3', 'bi-clock-history', 'bi-journal-text', 'bi-upc-scan', 'bi-bank'],
            $botoes->each(static fn (Crawler $b): string => trim(str_replace('bi ', '', (string) $b->filter('.ia-comando-ico > i')->attr('class')))),
        );

        $secoes = $crawler->filter(self::ANALISE . ' > section.ia-comando');
        self::assertSame(
            ['analisar_pasta', 'identificar_riscos', 'verificar_prazos', 'processo_parado', 'proximas_providencias', 'briefing', 'linha_do_tempo', 'analisar_historico', 'raio_x', 'analise_juridica'],
            $secoes->each(static fn (Crawler $s): ?string => $s->attr('data-ia-comando')),
        );
        // Um por vez, como o desenho: sem análise em andamento, o primeiro aparece; os outros, ocultos.
        self::assertSame('true', $botoes->eq(0)->attr('aria-pressed'));
        self::assertNull($secoes->eq(0)->attr('hidden'));
        self::assertNotNull($secoes->eq(1)->attr('hidden'));
        self::assertSame('ia-comando-identificar_riscos', $botoes->eq(1)->attr('aria-controls'));
        // A grade "O que a IA sabe" vem com o painel carregado (ver OQueAIaSabeTelaTest), no topo da aba.
        self::assertSame(0, $crawler->filter(self::DRAWER . ' .ia-sabe')->count(), 'no HTML da pasta o painel ainda não carregou');
    }

    #[TestDox('Aba Relatórios: o texto do desenho, os 5 relatórios (nome e descrição) e "Gerar <nome em minúsculas>"')]
    public function testRelatorios(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrirPasta($client, (int) $pasta->getId());

        self::assertSame('Relatórios executivos montados com os dados autorizados desta pasta e do cliente.', trim($crawler->filter(self::RELATORIOS . ' > p.ia-aba-intro')->text()));
        $botoes = $crawler->filter(self::RELATORIOS . ' > .ia-relatorios > button.ia-relatorio-btn[data-ia-escolher]');
        self::assertSame(
            ['Relatório da pasta', 'Resumo para o cliente', 'Relatório de prazos', 'Relatório de risco', 'Linha do tempo'],
            $botoes->each(static fn (Crawler $b): string => trim($b->filter('.ia-relatorio-nome')->text())),
        );
        self::assertSame(
            ['Situação, processo, prazos e providências', 'Linguagem simples, pronto para enviar', 'O que vence e quem é responsável', 'Pontos críticos e prioridades', 'Fatos e registros em ordem'],
            $botoes->each(static fn (Crawler $b): string => trim($b->filter('.ia-relatorio-desc')->text())),
        );

        $ctas = $crawler->filter(self::RELATORIOS . ' > section.ia-comando > button.ia-cta[data-ia-gerar]');
        self::assertSame(
            ['Gerar relatório da pasta', 'Gerar resumo para o cliente', 'Gerar relatório de prazos', 'Gerar relatório de risco', 'Gerar linha do tempo'],
            $ctas->each(static fn (Crawler $b): string => trim($b->text())),
        );
        self::assertSame("/pasta/{$pasta->getId()}/ia/comandos/relatorio_pasta/analises", $ctas->eq(0)->attr('data-ia-url'));
        self::assertSame(1, $ctas->eq(0)->filter('i.bi-file-earmark-text')->count());
        self::assertSame('true', $botoes->eq(0)->attr('aria-pressed'));
        self::assertNotNull($crawler->filter(self::RELATORIOS . ' > section.ia-comando[data-ia-comando="resumo_cliente"]')->attr('hidden'));
    }
}
