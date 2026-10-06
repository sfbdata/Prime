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
 * Tendência das quatro colunas de ESTOQUE na linha de Total (Metas ativas, Vencidas, Prazos
 * próximos, Demandas ativas): a pílula só aparece quando existe a foto diária de
 * `data_de − 1` para as pessoas da tela, e a cor segue o significado do README — Vencidas e
 * Prazos caindo é verde, as filas (ativas) são sempre cinza.
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

    #[TestDox('com foto de data_de − 1 de todos, as 4 colunas de estoque ganham pílula com a cor do significado')]
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
        foreach ([self::ATIVAS, self::VENCIDAS, self::PRAZOS, self::DEMANDAS_ATIVAS] as $i) {
            self::assertCount(1, $tds->eq($i)->filter('td > .db-total-cel > .db-total-num + .db-tend'), 'pílula na coluna ' . $i);
            self::assertCount(0, $tds->eq($i)->filter('.db-total-espaco'), 'sem espaçador quando há pílula (coluna ' . $i . ')');
        }

        // Ativas: 20 → 0 é queda, mas fila é sempre cinza.
        self::assertSame('desce', $tds->eq(self::ATIVAS)->filter('.db-tend')->attr('data-tendencia'));
        self::assertStringContainsString('db-tend--igual', (string) $tds->eq(self::ATIVAS)->filter('.db-tend')->attr('class'));
        self::assertStringContainsString('db-tend--igual', (string) $tds->eq(self::DEMANDAS_ATIVAS)->filter('.db-tend')->attr('class'));
        // Vencidas: 10 → 0 caiu, e cair é bom.
        self::assertStringContainsString('db-tend--bom', (string) $tds->eq(self::VENCIDAS)->filter('.db-tend')->attr('class'));
        self::assertStringContainsString('(10 → 0)', (string) $tds->eq(self::VENCIDAS)->filter('.db-tend')->attr('title'));

        // Celular: o card de Total leva as 7 pílulas.
        self::assertCount(7, $crawler->filter('.db-cel-card--total .db-tend'));
    }

    #[TestDox('sem foto naquele dia, as 4 colunas de estoque ficam sem pílula (só o espaçador)')]
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
    }
}
