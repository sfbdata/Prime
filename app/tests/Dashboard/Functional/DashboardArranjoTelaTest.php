<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\DashboardController;
use App\Entity\Tarefa\Tarefa;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tarefa\TarefaFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Zenstruck\Foundry\Test\Factories;

/**
 * Arranjo da tela do Dashboard no visual aprovado (trilha A, entregas A7–A9).
 *
 * As asserções usam o combinador de filho direto (`>`): é ele que distingue
 * "está no lugar certo da casca" de "existe em algum lugar da página" — que
 * seria verdade mesmo com o layout quebrado. Suíte verde não diz nada sobre
 * aparência; o que dá para travar aqui é a ESTRUTURA.
 */
#[CoversClass(DashboardController::class)]
final class DashboardArranjoTelaTest extends DashboardWebTestCase
{
    use Factories;

    #[TestDox('A barra de filtro é filha DIRETA do root persistente, fora da região que recarrega')]
    public function testBarraDeFiltroEFilhaDiretaDoRoot(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('section.db-page')->count(), 'A tela precisa da classe raiz .db-page (escopo do CSS)');
        self::assertSame(
            1,
            $crawler->filter('.db-page [data-filtro-root] > .db-filtro-wrap')->count(),
            'A barra de filtro tem de ser filha direta do root: é o `order` do CSS que a põe entre o título e os cards',
        );
        self::assertSame(
            1,
            $crawler->filter('.db-page [data-filtro-root] > .db-filtro-wrap > form[data-filtro-form]')->count(),
            'O form do parcial global continua dentro da barra (contrato do filtro-tabela.js)',
        );
    }

    #[TestDox('Os cards vivem na região que recarrega, filha direta do root')]
    public function testCardsVivemNaRegiaoQueRecarrega(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSame(
            1,
            $crawler->filter('.db-page [data-filtro-root] > [data-filtro-resultado] > .db-cards-row')->count(),
            'Os cards têm de ser filhos diretos de [data-filtro-resultado] (display:contents): só assim o `order` os intercala com o filtro',
        );
        self::assertSame(
            1,
            $crawler->filter('.db-page [data-filtro-root] > [data-filtro-resultado] > .db-table-card')->count(),
            'A tabela também recarrega com o filtro e participa do mesmo flex',
        );
    }

    #[TestDox('São 4 cards, cada um dentro do próprio wrapper de entrada, e o primeiro é Pastas criadas')]
    public function testQuatroCardsNaOrdemDoDesenho(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarGestorLogado($client);

        // O gestor abriu 2 pastas: o card "Pastas criadas" é a soma da coluna.
        PastaFactory::createMany(2, ['tenant' => $tenant, 'criadoPor' => $user]);

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $cards = $crawler->filter('.db-cards-row > * > .db-stat-card');
        self::assertCount(4, $cards, 'Quatro cards, cada um filho direto de um wrapper que é filho direto da grade');

        $primeiro = $cards->first();
        self::assertStringContainsString('Pastas criadas', $primeiro->text());
        self::assertSame('2', trim($primeiro->filter('.db-stat-num')->text()));
        // o valor final vai em data-alvo para a contagem do dashboard.js (e para quem não tem JS)
        self::assertSame('2', $primeiro->filter('.db-stat-num')->attr('data-alvo'));

        $rotulos = $cards->each(static fn ($c): string => trim($c->filter('.db-card-rotulo')->text()));
        self::assertSame(['Pastas criadas', 'Metas ativas', 'Demandas urgentes', 'Meta global batida'], $rotulos);

        // Nada fake: os links "ver pastas"/"ver metas" do desenho dependem de função nova.
        self::assertCount(0, $cards->filter('a'), 'Card não traz link enquanto o destino não existe');
    }

    #[TestDox('O card da meta global traz a legenda "X de Y metas concluídas" com os números reais')]
    public function testLegendaDaMetaGlobal(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);

        $pasta = PastaFactory::createOne(['tenant' => $tenant])->_real();
        TarefaFactory::createOne(['pasta' => $pasta, 'status' => Tarefa::STATUS_PENDENTE]);
        TarefaFactory::createOne(['pasta' => $pasta, 'status' => Tarefa::STATUS_CONCLUIDA]);

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $card = $crawler->filter('.db-cards-row > * > .db-stat-card--global');
        self::assertCount(1, $card);
        self::assertSame('50', trim($card->filter('.db-stat-num')->text()));
        self::assertSame(
            '1 de 2 metas concluídas',
            preg_replace('/\s+/', ' ', trim($card->filter('.db-card-legenda')->text())),
        );
    }

    #[TestDox('O título vive na casca, antes da barra de filtro, e não traz mais o ícone de velocímetro')]
    public function testTituloNaCascaAntesDoFiltro(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $titulo = $crawler->filter('.db-page [data-filtro-root] > h1.db-titulo');
        self::assertSame(1, $titulo->count());
        self::assertSame('Dashboard', trim($titulo->text()));
        self::assertSame(0, $titulo->filter('i')->count(), 'O desenho mostra o título sem ícone');

        // O estilo subiu para public/css/dashboard.css: nada inline na casca.
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('css/dashboard.css', $body);
        self::assertStringNotContainsString('.db-stat-card {', $body, 'O <style> inline do Dashboard saiu da casca');
    }
}
