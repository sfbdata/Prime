<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\DashboardController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Visual do Painel do Escritório (desenho 01 - Dashboard - VS 25.10.26).
 *
 * Trava o que é estrutura: o título novo, os quatro cards sem glifo e com a dica do desenho
 * (data-tip), o halo com anel que ficou, e o Total sem a calculadora (tabela e celular).
 * Arranjo por filho direto, para o teste distinguir "está no card" de "existe na página".
 * Cor, raio e degradê seguem invisíveis para o PHPUnit: ficam para a conferência visual.
 */
#[CoversClass(DashboardController::class)]
#[Group('dashboard')]
final class DashboardPainelVisualTelaTest extends DashboardWebTestCase
{
    private const CARD = '.db-page [data-filtro-root] > [data-filtro-resultado] > .db-cards-row > .db-card-wrap > .db-stat-card';

    #[TestDox('Título "Painel do Escritório" no h1, na aba do navegador e na marca do PDF')]
    public function testTituloNovo(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSame('Painel do Escritório', trim($crawler->filter('.db-page [data-filtro-root] > h1.db-titulo > .db-titulo-texto')->text()));
        self::assertStringContainsString('Painel do Escritório', $crawler->filter('title')->text());
        self::assertSame('BlueJus · Painel do Escritório', trim($crawler->filter('.db-page [data-filtro-root] > .db-print-cabecalho > .db-print-cabecalho-esq > .db-print-marca')->text()));
        self::assertStringNotContainsString('>Dashboard<', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Os quatro cards saem sem glifo (nenhum SVG) e mantêm o halo com o anel no canto')]
    public function testCardsSemGlifo(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertCount(4, $crawler->filter(self::CARD));
        self::assertCount(0, $crawler->filter(self::CARD . ' > .db-glifo'), 'O desenho VS 25.10.26 tirou os glifos');
        self::assertCount(0, $crawler->filter(self::CARD . ' svg'), 'Nenhum SVG dentro dos cards');
        self::assertCount(4, $crawler->filter(self::CARD . ' > .db-card-halo > .db-card-anel'), 'O halo com anel (dc back/ring) continua');
    }

    #[TestDox('Cada card traz a dica do desenho em data-tip e o número dele em data-dica')]
    public function testDicasDosCards(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $esperado = [
            'pastas'   => ['3', 'Soma das pastas abertas no período escolhido. Clique para ver a lista. Botão direito: histórico.'],
            'metas'    => ['0', 'Metas em andamento no período. Clique para abrir. Botão direito: histórico.'],
            'urgentes' => ['1', 'Pastas marcadas como urgentes. Clique para ver quais são. Botão direito: histórico.'],
            'global'   => ['2', 'Percentual das metas concluídas no período. Botão direito: histórico.'],
        ];
        foreach ($esperado as $card => [$n, $texto]) {
            $el = $crawler->filter(self::CARD . '.db-stat-card--' . $card);
            self::assertCount(1, $el, $card);
            self::assertSame($n, $el->attr('data-dica'), 'data-dica de ' . $card);
            self::assertSame($texto, $el->attr('data-tip'), 'data-tip de ' . $card);
            self::assertStringNotContainsString('—', $texto);
            self::assertStringNotContainsString('–', $texto);
        }
    }

    #[TestDox('O Total da tabela e o card de Total do celular saem sem a calculadora')]
    public function testTotalSemCalculadora(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $this->criarColaborador($tenant, 'Ana Total');

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $rotulo = $crawler->filter('.db-table-card table > tfoot > tr.db-total-row > th.db-td-colab > .db-colab > .db-total-rotulo');
        self::assertCount(1, $rotulo, 'O Total continua na tabela');
        self::assertCount(0, $crawler->filter('.db-table-card table > tfoot .bi-calculator'));
        self::assertCount(0, $crawler->filter('.db-table-card table > tfoot .db-total-icone'));
        self::assertCount(1, $crawler->filter('.db-cel-card--total > .db-cel-total-cab > .db-total-rotulo'), 'O card de Total do celular continua');
        self::assertCount(0, $crawler->filter('.db-cel-card--total .bi-calculator'));
        self::assertCount(0, $crawler->filter('.db-page .bi-calculator'), 'Nenhuma calculadora no painel');
    }
}
