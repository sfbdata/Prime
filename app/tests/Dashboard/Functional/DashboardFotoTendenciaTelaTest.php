<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\DashboardController;
use App\Dashboard\Repository\DashboardFotoRepository;
use App\Dashboard\UseCase\ObterDadosDashboardUseCase;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Tendência de Vencidas e Prazos próximos na linha de Total: a pílula só aparece quando existe
 * a foto diária de `data_de − 1` para as pessoas da tela, e a cor segue o significado do
 * README — cair é verde. Metas/Demandas ativas NÃO têm pílula nem com foto: o painel as filtra
 * por criação no período e a foto guarda o estoque inteiro (bases diferentes).
 *
 * Período fixo de setembro/2026 → a foto procurada é a de 31/08/2026. Nada nestes cenários
 * tem meta ou pasta: o estoque de agora é zero em tudo, e o que muda é só a foto.
 */
#[CoversClass(DashboardController::class)]
#[CoversClass(ObterDadosDashboardUseCase::class)]
#[Group('dashboard')]
final class DashboardFotoTendenciaTelaTest extends DashboardWebTestCase
{
    private const PERIODO = 'data_de=2026-09-01&data_ate=2026-09-30';
    private const VESPERA = '2026-08-31';

    /** Colunas do <tfoot>: [cargo, metas, ativas, vencidas, prazos, demandas, demandas ativas, criadas]. */
    private const ATIVAS = 2;
    private const VENCIDAS = 3;
    private const PRAZOS = 4;
    private const DEMANDAS_ATIVAS = 6;

    /** @param int[] $userIds */
    private function fotografar(Tenant $tenant, string $dia, array $userIds): void
    {
        $contagens = [];
        foreach ($userIds as $id) {
            $contagens[$id] = ['metas_ativas' => 10, 'demandas_ativas' => 4, 'metas_vencidas' => 5, 'prazos_proximos' => 0];
        }

        static::getContainer()->get(DashboardFotoRepository::class)
            ->gravar($tenant, new \DateTimeImmutable($dia), $contagens);
    }

    private function celulasDoTotal(Crawler $crawler): Crawler
    {
        return $crawler->filter('table > tfoot > tr.db-total-row > td');
    }

    #[TestDox('com foto de data_de − 1 de todos, Vencidas e Prazos ganham pílula; as ativas, não')]
    public function testComFotoMostraPilulas(): void
    {
        $client         = static::createClient();
        [$gestora, $tenant] = $this->criarGestorLogado($client);
        $ana            = $this->criarColaborador($tenant, 'Ana Estoque');
        $this->fotografar($tenant, self::VESPERA, [(int) $gestora->getId(), (int) $ana->getId()]);

        $crawler = $client->xmlHttpRequest('GET', '/dashboard?' . self::PERIODO);
        self::assertResponseIsSuccessful();

        $tds = $this->celulasDoTotal($crawler);
        self::assertCount(8, $tds);
        foreach ([self::VENCIDAS, self::PRAZOS] as $i) {
            self::assertCount(1, $tds->eq($i)->filter('td > .db-total-cel > .db-total-num + .db-tend'), 'pílula na coluna ' . $i);
            self::assertCount(0, $tds->eq($i)->filter('.db-total-espaco'), 'sem espaçador quando há pílula (coluna ' . $i . ')');
        }
        foreach ([self::ATIVAS, self::DEMANDAS_ATIVAS] as $i) {
            self::assertCount(0, $tds->eq($i)->filter('.db-tend'), 'ativas sem tendência, mesmo com foto (coluna ' . $i . ')');
            self::assertCount(1, $tds->eq($i)->filter('.db-total-espaco'));
        }

        // Vencidas: 10 → 0 caiu, e cair é bom.
        self::assertStringContainsString('db-tend--bom', (string) $tds->eq(self::VENCIDAS)->filter('.db-tend')->attr('class'));
        self::assertStringContainsString('(10 → 0)', (string) $tds->eq(self::VENCIDAS)->filter('.db-tend')->attr('title'));

        // Celular: o card de Total leva 5 pílulas (3 reconstruíveis + Vencidas + Prazos).
        self::assertCount(5, $crawler->filter('.db-cel-card--total .db-tend'));
    }

    #[TestDox('sem foto naquele dia, Vencidas e Prazos ficam sem pílula (só o espaçador)')]
    public function testSemFotoSemPilula(): void
    {
        $client             = static::createClient();
        [$gestora, $tenant] = $this->criarGestorLogado($client);
        $ana                = $this->criarColaborador($tenant, 'Ana Estoque');
        // Foto de OUTRO dia (a véspera da véspera): não serve.
        $this->fotografar($tenant, '2026-08-30', [(int) $gestora->getId(), (int) $ana->getId()]);

        $crawler = $client->xmlHttpRequest('GET', '/dashboard?' . self::PERIODO);
        self::assertResponseIsSuccessful();

        $tds = $this->celulasDoTotal($crawler);
        foreach ([self::ATIVAS, self::VENCIDAS, self::PRAZOS, self::DEMANDAS_ATIVAS] as $i) {
            self::assertCount(0, $tds->eq($i)->filter('.db-tend'), 'sem foto, sem pílula (coluna ' . $i . ')');
            self::assertCount(1, $tds->eq($i)->filter('.db-total-espaco'));
        }
        // A irmã prova que a tendência rodou: as 3 reconstruíveis seguem com pílula.
        self::assertCount(3, $crawler->filter('table > tfoot .db-tend'));
        self::assertCount(3, $crawler->filter('.db-cel-card--total .db-tend'));
    }

    #[TestDox('foto faltando para uma das pessoas da tela: o Total não compara')]
    public function testFotoParcialSemPilula(): void
    {
        $client         = static::createClient();
        [$gestora, $tenant] = $this->criarGestorLogado($client);
        $this->criarColaborador($tenant, 'Ana Sem Foto');
        $this->fotografar($tenant, self::VESPERA, [(int) $gestora->getId()]);

        $crawler = $client->xmlHttpRequest('GET', '/dashboard?' . self::PERIODO);
        self::assertResponseIsSuccessful();

        self::assertCount(3, $crawler->filter('table > tfoot .db-tend'));
    }

    #[TestDox('foto das mesmas pessoas no mesmo dia, mas de OUTRO escritório, não acende pílula (isolamento)')]
    public function testFotoDeOutroEscritorioNaoVaza(): void
    {
        $client             = static::createClient();
        [$gestora, $tenant] = $this->criarGestorLogado($client);
        $ana                = $this->criarColaborador($tenant, 'Ana Estoque');
        $outro              = $this->criarTenant();
        $this->fotografar($outro, self::VESPERA, [(int) $gestora->getId(), (int) $ana->getId()]);

        $crawler = $client->xmlHttpRequest('GET', '/dashboard?' . self::PERIODO);
        self::assertResponseIsSuccessful();

        self::assertCount(3, $crawler->filter('table > tfoot .db-tend'), 'só as 3 reconstruíveis; a foto do vizinho não conta');
    }

    // ── Seta por linha em Vencidas e Prazos (3ª passada, D1: dc L806-809) ─────────────

    private function celula(Crawler $crawler, string $nome, string $coluna): Crawler
    {
        $linha = $crawler->filter('table.db-table > tbody > tr')->reduce(
            static fn (Crawler $tr): bool => str_contains($tr->filter('.db-colab-nome')->attr('title') ?? '', $nome),
        );
        self::assertCount(1, $linha, 'linha de ' . $nome);

        return $linha->filter('tr > td[data-coluna="' . $coluna . '"]');
    }

    private function blocoDoCard(Crawler $crawler, string $nome, string $coluna): Crawler
    {
        $card = $crawler->filter('.db-cel-lista > .db-cel-card')->reduce(
            static fn (Crawler $c): bool => str_contains($c->text(), $nome),
        );
        self::assertCount(1, $card, 'card de ' . $nome);

        return $card->filter('.db-cel-grade > .db-cel-bloco[data-coluna="' . $coluna . '"]');
    }

    #[TestDox('com a foto da pessoa, Vencidas e Prazos ganham seta NA LINHA e no card do celular; cair é bom')]
    public function testSetaPorLinhaComFoto(): void
    {
        $client             = static::createClient();
        [$gestora, $tenant] = $this->criarGestorLogado($client);
        $ana                = $this->criarColaborador($tenant, 'Ana Estoque');
        $this->fotografar($tenant, self::VESPERA, [(int) $gestora->getId(), (int) $ana->getId()]);

        $crawler = $client->xmlHttpRequest('GET', '/dashboard?' . self::PERIODO);
        self::assertResponseIsSuccessful();

        // Vencidas: foto 5 → agora 0, caiu: seta para baixo, verde (sentido 'baixa').
        $venc = $this->celula($crawler, 'Ana Estoque', 'metas_vencidas');
        $seta = $venc->filter('td > .db-cel.db-cel--seta > .db-num + .db-seta');
        self::assertCount(1, $seta, 'número e seta lado a lado, dentro da célula');
        self::assertStringContainsString('db-seta--bom', (string) $seta->attr('class'));
        self::assertSame('desce', $seta->attr('data-tendencia'));
        self::assertStringContainsString('(5 → 0)', (string) $seta->attr('aria-label'));

        // Prazos: 0 na foto e 0 agora → espaço reservado, sem seta (não desalinha a coluna).
        $praz = $this->celula($crawler, 'Ana Estoque', 'prazos');
        self::assertCount(1, $praz->filter('td > .db-cel.db-cel--seta > .db-num + .db-seta.db-seta--vazia'));

        // Ativas seguem sem seta, mesmo com foto (bases diferentes).
        self::assertCount(0, $this->celula($crawler, 'Ana Estoque', 'metas_ativas')->filter('.db-seta'));

        // Celular: o mesmo bloco ganha a seta ao lado do número.
        $bloco = $this->blocoDoCard($crawler, 'Ana Estoque', 'metas_vencidas');
        self::assertCount(1, $bloco->filter('.db-cel-bloco > .db-cel-valor > .db-num + .db-seta.db-seta--bom'));
        self::assertCount(1, $this->blocoDoCard($crawler, 'Ana Estoque', 'prazos')->filter('.db-cel-bloco > .db-cel-valor > .db-seta--vazia'));
    }

    #[TestDox('seta por linha em Prazos vem da coluna prazos_proximos da foto; a irmã 0 → 0 fica só com o espaço')]
    public function testSetaPorLinhaEmPrazos(): void
    {
        $client             = static::createClient();
        [$gestora, $tenant] = $this->criarGestorLogado($client);
        static::getContainer()->get(DashboardFotoRepository::class)->gravar(
            $tenant,
            new \DateTimeImmutable(self::VESPERA),
            [(int) $gestora->getId() => ['metas_ativas' => 0, 'demandas_ativas' => 0, 'metas_vencidas' => 0, 'prazos_proximos' => 3]],
        );

        $crawler = $client->xmlHttpRequest('GET', '/dashboard?' . self::PERIODO);
        self::assertResponseIsSuccessful();

        // Prazos: foto 3 → agora 0, caiu: bom. A irmã (Vencidas 0 → 0) fica vazia.
        $praz = $this->celula($crawler, (string) $gestora->getFullName(), 'prazos')->filter('.db-seta');
        self::assertStringContainsString('db-seta--bom', (string) $praz->attr('class'));
        self::assertCount(1, $this->celula($crawler, (string) $gestora->getFullName(), 'metas_vencidas')->filter('.db-seta--vazia'));
    }

    #[TestDox('sem a foto daquela pessoa, a linha dela fica sem seta em Vencidas e Prazos; a de quem tem foto, não')]
    public function testSetaPorLinhaSoParaQuemTemFoto(): void
    {
        $client             = static::createClient();
        [$gestora, $tenant] = $this->criarGestorLogado($client);
        $this->criarColaborador($tenant, 'Ana Sem Foto');
        $this->fotografar($tenant, self::VESPERA, [(int) $gestora->getId()]);

        $crawler = $client->xmlHttpRequest('GET', '/dashboard?' . self::PERIODO);
        self::assertResponseIsSuccessful();

        foreach (['metas_vencidas', 'prazos'] as $coluna) {
            $semFoto = $this->celula($crawler, 'Ana Sem Foto', $coluna);
            self::assertCount(0, $semFoto->filter('.db-seta'), 'sem foto, sem seta (' . $coluna . ')');
            self::assertCount(0, $semFoto->filter('.db-cel--seta'), 'sem seta, sem o recuo que a equilibra (' . $coluna . ')');
            self::assertCount(1, $this->celula($crawler, (string) $gestora->getFullName(), $coluna)->filter('td > .db-cel--seta > .db-seta'));
        }
    }

    #[TestDox('foto da mesma pessoa em OUTRO escritório não acende seta na linha (isolamento)')]
    public function testSetaPorLinhaNaoVazaDeOutroEscritorio(): void
    {
        $client             = static::createClient();
        [$gestora, $tenant] = $this->criarGestorLogado($client);
        $ana                = $this->criarColaborador($tenant, 'Ana Estoque');
        $this->fotografar($this->criarTenant(), self::VESPERA, [(int) $gestora->getId(), (int) $ana->getId()]);

        $crawler = $client->xmlHttpRequest('GET', '/dashboard?' . self::PERIODO);
        self::assertResponseIsSuccessful();

        foreach (['metas_vencidas', 'prazos'] as $coluna) {
            self::assertCount(0, $crawler->filter('table.db-table > tbody > tr > td[data-coluna="' . $coluna . '"] .db-seta'), $coluna);
            self::assertCount(0, $crawler->filter('.db-cel-grade > .db-cel-bloco[data-coluna="' . $coluna . '"] .db-seta'), $coluna . ' (celular)');
        }
        // A irmã prova que a tendência rodou: Total metas tem seta (vazia: 0 → 0) nas linhas.
        self::assertGreaterThanOrEqual(1, $crawler->filter('table.db-table > tbody > tr > td[data-coluna="metas"] .db-seta')->count());
    }

    #[TestDox('sem período não há pílula nenhuma, mesmo com foto')]
    public function testSemPeriodoSemPilula(): void
    {
        $client             = static::createClient();
        [$gestora, $tenant] = $this->criarGestorLogado($client);
        $this->fotografar($tenant, self::VESPERA, [(int) $gestora->getId()]);

        $crawler = $client->xmlHttpRequest('GET', '/dashboard');
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('table > tfoot .db-tend'));
        self::assertCount(0, $crawler->filter('.db-cel-card--total .db-tend'));
        self::assertCount(0, $crawler->filter('table.db-table > tbody .db-seta'), 'nem seta nas linhas');
    }
}
