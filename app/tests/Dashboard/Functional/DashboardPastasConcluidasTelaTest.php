<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\DashboardController;
use App\Dashboard\Preferencia\CatalogoDePreferenciasDoDashboard;
use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaAndamento;
use App\Tests\Factory\Pasta\PastaFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * Coluna extra "Pastas concluídas" na TELA (spec dashboard-parte-1, PR3; desenho
 * `EXTRAS.pastasConcluidas`: "Pastas concluídas", "Demandas encerradas no período", soma): pastas
 * que chegaram à etapa Finalizada do andamento no período, por responsável, com o outro escritório,
 * a pasta excluída e a que voltou de fase fora, e a tendência contra o período anterior equivalente.
 * A pasta chega a Finalizada pelo PastaAndamento::gravar(), o mesmo caminho da tela da pasta.
 */
#[CoversClass(DashboardController::class)]
#[Group('dashboard')]
final class DashboardPastasConcluidasTelaTest extends DashboardWebTestCase
{
    use Factories;

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function ligar(Tenant $tenant, User $quemOlha): void
    {
        static::getContainer()->get(PreferenciaDoUsuarioRepository::class)
            ->gravar($tenant, $quemOlha, CatalogoDePreferenciasDoDashboard::COLUNAS_EXTRAS, ['pastas_concluidas']);
    }

    private function finalizada(Tenant $tenant, User $responsavel, string $em): PastaAndamento
    {
        $pasta = PastaFactory::createOne(['tenant' => $tenant, 'responsavel' => $responsavel])->_real();
        $andamento = new PastaAndamento($tenant, $pasta, PastaAndamento::VIA_JUDICIAL);
        $andamento->gravar(9, ['em' => [9 => (new \DateTimeImmutable($em))->format(\DATE_ATOM)], 'pul' => [], 'extra' => []]);
        $this->em()->persist($andamento);
        $this->em()->flush();

        return $andamento;
    }

    private function linhaDe(Crawler $crawler, string $nome): Crawler
    {
        $linha = $crawler->filter('.db-table-card table.db-table > tbody > tr')->reduce(
            static fn (Crawler $tr): bool => str_contains($tr->filter('.db-colab-nome')->attr('title') ?? '', $nome),
        );
        self::assertCount(1, $linha, 'Linha de ' . $nome);

        return $linha;
    }

    #[TestDox('Cabeçalho com rótulo e ajuda do desenho; conta as que chegaram a Finalizada no período por responsável, na linha e no Total')]
    public function testColunaPastasConcluidas(): void
    {
        $client = static::createClient();
        [$gestora, $tenant] = $this->criarGestorLogado($client);
        $ana = $this->criarColaborador($tenant, 'Ana Concluidas');
        $this->ligar($tenant, $gestora);
        $this->finalizada($tenant, $ana, '2024-03-05 10:00');
        $this->finalizada($tenant, $ana, '2024-03-20 10:00');
        $this->finalizada($tenant, $gestora, '2024-03-21 10:00');
        $this->finalizada($tenant, $ana, '2024-02-10 10:00'); // fora do período

        $crawler = $client->request('GET', '/dashboard?data_de=2024-03-01&data_ate=2024-03-31');

        self::assertResponseIsSuccessful();
        $th = $crawler->filter('table.db-table > thead > tr > th[data-coluna="pastas_concluidas"]');
        self::assertSame('Pastas concluídas', trim($th->text()));
        self::assertStringStartsWith('Demandas encerradas no período', (string) $th->attr('title'));
        self::assertSame('2', trim($this->linhaDe($crawler, 'Ana Concluidas')->filter('td[data-coluna="pastas_concluidas"] .db-num')->text()));
        self::assertStringContainsString('3', $crawler->filter('table.db-table > tfoot td[data-coluna="pastas_concluidas"]')->text());
    }

    #[TestDox('Outro escritório, pasta excluída (lápide) e pasta que voltou de fase ficam fora; arquivada fora de Finalizada não conta')]
    public function testIsolamentoExcluidaEVoltouDeFase(): void
    {
        $client = static::createClient();
        [$gestora, $tenant] = $this->criarGestorLogado($client);
        $ana = $this->criarColaborador($tenant, 'Ana Isolada');
        $this->ligar($tenant, $gestora);
        $this->finalizada($tenant, $ana, '2024-03-05 10:00');
        $this->finalizada($this->criarTenant(), $ana, '2024-03-05 10:00');
        $this->finalizada($tenant, $ana, '2024-03-06 10:00')->getPasta()->marcarExcluida($gestora, new \DateTimeImmutable());
        $this->finalizada($tenant, $ana, '2024-03-07 10:00')->gravar(5, ['em' => [5 => '2024-03-08T10:00:00-03:00'], 'pul' => [], 'extra' => []]);
        PastaFactory::createOne(['tenant' => $tenant, 'responsavel' => $ana])->_real()->setSituacao(Pasta::SITUACAO_ARQUIVADA);
        $this->em()->flush();

        $crawler = $client->request('GET', '/dashboard?data_de=2024-03-01&data_ate=2024-03-31');

        self::assertSame('1', trim($this->linhaDe($crawler, 'Ana Isolada')->filter('td[data-coluna="pastas_concluidas"] .db-num')->text()));
    }

    #[TestDox('Tem tendência: seta na linha e pílula no Total contra o período anterior equivalente (subir é bom)')]
    public function testTendencia(): void
    {
        $client = static::createClient();
        [$gestora, $tenant] = $this->criarGestorLogado($client);
        $this->ligar($tenant, $gestora);
        $this->finalizada($tenant, $gestora, '2024-03-10 10:00');
        $this->finalizada($tenant, $gestora, '2024-03-12 10:00');
        $this->finalizada($tenant, $gestora, '2024-02-20 10:00');

        $crawler = $client->request('GET', '/dashboard?data_de=2024-03-01&data_ate=2024-03-31');

        self::assertGreaterThanOrEqual(1, $crawler->filter('table.db-table > tbody td[data-coluna="pastas_concluidas"] .db-seta')->count());
        self::assertSame('+100%', trim($crawler->filter('table.db-table > tfoot td[data-coluna="pastas_concluidas"] .db-tend')->text()));
    }
}
