<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\DashboardController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Segmentado de período (Este mês · 60 dias · 90 dias · Este ano) e o
 * "Atualizado às" — entrega A10 da trilha visual.
 *
 * O botão ativo é calculado no servidor comparando as datas dos filtros com as
 * quatro faixas (o JS refaz a mesma conta lendo os atributos data-de/data-ate).
 * Faixas do desenho VS 25.10.26: Este mês do dia 1º ao último dia do mês; 60 e 90
 * dias com hoje incluído (hoje − 59 e hoje − 89); Este ano de 1º/jan até hoje.
 */
#[CoversClass(DashboardController::class)]
final class DashboardPeriodoSegmentadoTest extends DashboardWebTestCase
{
    #[TestDox('Com data_de/data_ate do mês inteiro (1º ao último dia), só o botão "Este mês" fica ativo')]
    public function testEsteMesAtivoQuandoAsDatasBatem(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $hoje = new \DateTimeImmutable('now');
        $crawler = $client->request('GET', '/dashboard?data_de=' . $hoje->format('Y-m-01') . '&data_ate=' . $hoje->format('Y-m-t'));

        self::assertResponseIsSuccessful();
        $ativos = $crawler->filter('.db-seg > .db-seg-btn.is-ativo');
        self::assertCount(1, $ativos, 'Exatamente um período ativo');
        self::assertSame('mes', $ativos->attr('data-periodo'));
        self::assertSame('Este mês', trim($ativos->text()));
        self::assertSame('true', $ativos->attr('aria-pressed'));
    }

    #[TestDox('Datas enviadas vazias (período aberto): nenhum botão fica ativo. Sem datas na URL o painel abre em Este mês (DashboardParte1TelaTest)')]
    public function testDatasVaziasNenhumBotaoAtivo(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $crawler = $client->request('GET', '/dashboard?data_de=&data_ate=');

        self::assertResponseIsSuccessful();
        self::assertCount(4, $crawler->filter('.db-seg > .db-seg-btn'));
        self::assertCount(0, $crawler->filter('.db-seg > .db-seg-btn.is-ativo'));
        self::assertCount(4, $crawler->filter('.db-seg > .db-seg-btn[aria-pressed="false"]'));
    }

    #[TestDox('Os botões carregam as datas do desenho: Este mês até o último dia; 60 e 90 dias com hoje incluído; Este ano até hoje')]
    public function testBotoesCarregamDatasReais(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $hoje = new \DateTimeImmutable('now');
        $esperado = [
            'mes' => [$hoje->format('Y-m-01'), $hoje->format('Y-m-t')],
            'd60' => [$hoje->modify('-59 days')->format('Y-m-d'), $hoje->format('Y-m-d')],
            'd90' => [$hoje->modify('-89 days')->format('Y-m-d'), $hoje->format('Y-m-d')],
            'ano' => [$hoje->format('Y-01-01'), $hoje->format('Y-m-d')],
        ];

        foreach ($esperado as $chave => [$de, $ate]) {
            $btn = $crawler->filter('.db-seg > .db-seg-btn[data-periodo="' . $chave . '"]');
            self::assertCount(1, $btn, $chave);
            self::assertSame($de, $btn->attr('data-de'), 'data-de de ' . $chave);
            self::assertSame($ate, $btn->attr('data-ate'), 'data-ate de ' . $chave);
            self::assertSame('button', $btn->attr('type'), 'botão não submete o form');
        }

        // Sem data na URL o painel abre em Este mês, e o botão dele vem ativo (servidor e tela concordam).
        self::assertSame('mes', $crawler->filter('.db-seg > .db-seg-btn.is-ativo')->attr('data-periodo'));

        // Dentro da caixa de filtros, antes do form do parcial (que não é tocado).
        self::assertCount(1, $crawler->filter('[data-filtro-root] > .db-filtro-wrap > .db-seg + form[data-filtro-form]'));
    }

    #[TestDox('"Atualizado às HH:MM" é renderizado no servidor DENTRO do fragmento, e volta no XHR')]
    public function testAtualizadoAsDentroDoFragmento(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $crawler = $client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();
        $el = $crawler->filter('[data-filtro-root] > [data-filtro-resultado] > .db-atualizado');
        self::assertCount(1, $el, 'Mora no fragmento (filho direto da região que recarrega), ao lado do título no flex do root');
        self::assertMatchesRegularExpression('/^Atualizado às \d{2}:\d{2}$/', preg_replace('/\s+/', ' ', trim($el->text())));

        // O XHR devolve só o fragmento — e a hora vem junto, renovada a cada recarga.
        $client->xmlHttpRequest('GET', '/dashboard');
        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertMatchesRegularExpression('/Atualizado às \d{2}:\d{2}/', $body);
        self::assertStringNotContainsString('db-seg-btn', $body, 'O segmentado é da casca, não do fragmento');
    }
}
