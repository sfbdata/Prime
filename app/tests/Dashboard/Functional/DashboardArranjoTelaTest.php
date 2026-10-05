<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\DashboardController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

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
