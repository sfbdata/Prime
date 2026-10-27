<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\DashboardController;
use App\Dashboard\Entity\DashboardMetaPastas;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "Teste · simular dia" do painel BlueJus Intelligence (desenho 01, rodapé): o controle, o aviso de
 * simulação, o recálculo do selo e do "dia X de Y" e os dias que não simulam. Nada é gravado.
 */
#[CoversClass(DashboardController::class)]
#[Group('dashboard')]
final class DashboardInteligenciaSimularDiaTelaTest extends DashboardWebTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function mesInteiro(): string
    {
        $mes = new \DateTimeImmutable('today');

        return 'data_de=' . $mes->format('Y-m-01') . '&data_ate=' . $mes->format('Y-m-t');
    }

    /** Um dia do mês corrente que não é hoje. */
    private function outroDia(): \DateTimeImmutable
    {
        $hoje = new \DateTimeImmutable('today');

        return $hoje->setDate((int) $hoje->format('Y'), (int) $hoje->format('n'), (int) $hoje->format('j') === 1 ? 2 : 1);
    }

    private function diaDoPeriodo(Crawler $crawler): string
    {
        return trim($crawler->filter('aside.db-ia-painel > .db-ia-corpo .db-ia-ritmo-topo .db-ia-dia')->first()->text());
    }

    #[TestDox('O rodapé traz "Teste · simular dia": controle de 1 ao último dia, no dia de hoje, sem aviso de simulação')]
    public function testControleNoRodape(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);
        $hoje = new \DateTimeImmutable('today');

        $crawler = $client->request('GET', '/dashboard?' . $this->mesInteiro());

        $sim = $crawler->filter('aside.db-ia-painel > .db-ia-rodape > .db-ia-sim');
        self::assertCount(1, $sim);
        self::assertSame('Teste', trim($sim->filter('.db-ia-sim-teste')->text()));
        self::assertSame('simular dia', trim($sim->filter('label')->text()));
        $range = $sim->filter('input[type=range][data-ia-simular]');
        self::assertSame('1', $range->attr('min'));
        self::assertSame($hoje->format('t'), $range->attr('max'));
        self::assertSame($hoje->format('j'), $range->attr('value'));
        self::assertSame($hoje->format('j'), trim($sim->filter('output')->text()));
        self::assertSame('Simular dia do período', $range->attr('aria-label'));
        self::assertCount(0, $crawler->filter('[data-ia-sim-aviso]'));
    }

    #[TestDox('Com dia_simulado: o painel avisa que é simulação e recalcula "dia X de Y" e o dia útil do selo')]
    public function testSimulaORecalculo(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarGestorLogado($client);
        $this->em()->persist(new DashboardMetaPastas($tenant, new \DateTimeImmutable('first day of this month'), 9999, $user));
        $this->em()->flush();
        $dia = $this->outroDia();

        $crawler = $client->request('GET', '/dashboard?' . $this->mesInteiro() . '&dia_simulado=' . $dia->format('Y-m-d'));

        self::assertResponseIsSuccessful();
        $aviso = $crawler->filter('aside.db-ia-painel > .db-ia-corpo > [data-ia-sim-aviso]');
        self::assertCount(1, $aviso, 'aviso no topo do corpo');
        self::assertStringContainsString($dia->format('d/m/Y'), $aviso->text());
        self::assertStringContainsString('Nada é gravado', $aviso->text());
        self::assertSame('dia ' . $dia->format('j') . ' de ' . $dia->format('t'), $this->diaDoPeriodo($crawler));
        self::assertSame($dia->format('j'), $crawler->filter('input[data-ia-simular]')->attr('value'));
        self::assertMatchesRegularExpression('/^dia útil \d+ de \d+$/u', trim($crawler->filter('.db-ia-meta-ritmo .db-ia-dia')->text()));
    }

    #[TestDox('O XHR dos filtros também respeita dia_simulado (o corpo trocado traz o aviso)')]
    public function testXhrSimula(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);
        $dia = $this->outroDia();

        $crawler = $client->request('GET', '/dashboard?' . $this->mesInteiro() . '&dia_simulado=' . $dia->format('Y-m-d'), [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#dbIaPainel > .db-ia-corpo > [data-ia-sim-aviso]'));
    }

    #[TestDox('Dia inválido, fora do período ou além do fim do mês não simula (sem aviso, sem erro)')]
    public function testDiasQueNaoSimulam(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);
        $hoje    = new \DateTimeImmutable('today');
        $proximo = $hoje->modify('first day of next month')->format('Y-m-d');

        foreach (['abc', '2000-01-01', $proximo, $hoje->format('Y-m-d'), '2026-02-31'] as $valor) {
            $crawler = $client->request('GET', '/dashboard?' . $this->mesInteiro() . '&dia_simulado=' . $valor);
            self::assertResponseIsSuccessful($valor);
            self::assertCount(0, $crawler->filter('[data-ia-sim-aviso]'), 'sem simulação: ' . $valor);
        }

        // Período que passa do mês corrente: o controle e o parâmetro param no fim do mês.
        $longo   = 'data_de=' . $hoje->format('Y-m-01') . '&data_ate=' . $hoje->modify('last day of +3 months')->format('Y-m-d');
        $crawler = $client->request('GET', '/dashboard?' . $longo . '&dia_simulado=' . $proximo);
        self::assertCount(0, $crawler->filter('[data-ia-sim-aviso]'));
        self::assertSame($hoje->format('t'), $crawler->filter('input[data-ia-simular]')->attr('max'));
    }

    #[TestDox('Sem período completo não há controle de simulação')]
    public function testSemPeriodoSemControle(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $crawler = $client->request('GET', '/dashboard?data_de=&data_ate=');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.db-ia-sim'));
    }

    #[TestDox('Simular não grava nada: o dia simulado não vira filtro nem preferência')]
    public function testNaoGravaNada(): void
    {
        $client = static::createClient();
        [$user] = $this->criarGestorLogado($client);
        $antes  = (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM preferencia_usuario WHERE user_id = :u', ['u' => $user->getId()]);

        $client->request('GET', '/dashboard?' . $this->mesInteiro() . '&dia_simulado=' . $this->outroDia()->format('Y-m-d'));

        $depois = (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM preferencia_usuario WHERE user_id = :u', ['u' => $user->getId()]);
        self::assertSame($antes, $depois);
    }
}
