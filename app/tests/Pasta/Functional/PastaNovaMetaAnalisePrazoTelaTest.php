<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Bloco "Análise do prazo" do modal "Nova meta" (desenho 02 de 22/10, L.98-174). Aqui se prova o arranjo e os
 * textos literais do desenho, com combinador de FILHO DIRETO. Nenhum texto visível diz "I.A.": a regra do dono é
 * "Análise do prazo". O cálculo é do servidor (`AnaliseDoPrazoDaMetaNovaTest`); o efeito visual é do smoke do dono.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaNovaMetaAnalisePrazoTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private function modal(): \Symfony\Component\DomCrawler\Crawler
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return $crawler->filter('#modalCriarTarefa');
    }

    #[TestDox('o bloco nasce escondido, aponta para a rota da análise e fica logo depois da linha de info do prazo')]
    public function testArranjoDoBloco(): void
    {
        $modal = $this->modal();

        $caixa = $modal->filter('.ps-nm-prazo > [data-analise-prazo].ps-ap');
        self::assertCount(1, $caixa);
        self::assertNotNull($caixa->attr('hidden'));
        self::assertMatchesRegularExpression('#^/tarefas/pasta/\d+/analise-do-prazo$#', (string) $caixa->attr('data-url'));

        $linha = $modal->filter('.ps-nm-prazo > .ps-ap-linha');
        self::assertCount(1, $linha);
        self::assertCount(1, $linha->filter('.ps-ap-linha > #tarefaPrazoInfo'));
        self::assertCount(1, $linha->filter('.ps-ap-linha > button.ps-ap-alternar[data-ap-alternar][hidden]'));
        self::assertSame('Análise', trim($linha->filter('.ps-ap-alternar > span')->text()));

        self::assertCount(1, $modal->filter('.ps-nm-prazo-linha > .ps-ap-chip-wrap[hidden] > button.ps-ap-chip[data-ap-chip]'));
        self::assertCount(1, $modal->filter('.ps-ap-chip-wrap > [role="tooltip"][data-ap-chip-dica]'));
        self::assertCount(0, $modal->filter('.ps-ap .card, .ps-ap .alert'), 'sem componentes do AdminLTE/Bootstrap');
    }

    #[TestDox('cabeçalho, 3 cards, painel Conferir, alerta, origem e conferência final com os textos literais do desenho')]
    public function testTextosDoDesenho(): void
    {
        $caixa = $this->modal()->filter('[data-analise-prazo]');

        self::assertSame('ANÁLISE DO PRAZO', trim($caixa->filter('.ps-ap-cab > .ps-ap-titulo')->text()));
        self::assertSame('Análise atualizada', trim($caixa->filter('.ps-ap-cab > [data-ap-atualizada]')->text()));
        self::assertSame('Conferir', trim($caixa->filter('.ps-ap-cab > button[data-ap-conferir]')->text()));

        $cards = $caixa->filter('.ps-ap-cards > .ps-ap-card');
        self::assertCount(3, $cards);
        self::assertSame(
            ['Prazo jurídico', 'Dias úteis até lá', 'Folga'],
            $cards->each(static fn ($c): string => trim($c->filter('.ps-ap-card > .ps-ap-card-rotulo')->text())),
        );

        self::assertStringContainsString('Confira as informações utilizadas:', $caixa->filter('.ps-ap-conf')->text());
        self::assertSame('Confirmar análise', trim($caixa->filter('.ps-ap-conf-botoes > [data-ap-confirmar]')->text()));
        self::assertSame('Corrigir informações', trim($caixa->filter('.ps-ap-conf-botoes > [data-ap-corrigir]')->text()));
        self::assertStringContainsString('Não consegui confirmar o prazo jurídico apenas com as informações disponíveis.', $caixa->filter('.ps-ap > .ps-ap-incerto')->text());
        self::assertSame('+ Informar origem do prazo', trim($caixa->filter('.ps-ap > button[data-ap-origem-btn]')->text()));
        self::assertSame(
            ['Data da publicação', 'Data da ciência/intimação'],
            $caixa->filter('.ps-ap-origem > label')->each(static fn ($l): string => trim($l->text())),
        );
        self::assertCount(2, $caixa->filter('.ps-ap-origem > label > input[type="date"]'));
        self::assertSame('Antes de salvar, confira', trim($caixa->filter('.ps-ap-ck > .ps-ap-card-rotulo')->text()));
        self::assertCount(1, $caixa->filter('.ps-ap > .ps-ap-apr[hidden]'), 'linha de aprendizado, escondida sem texto');
    }

    #[TestDox('nenhum texto do bloco diz "I.A." e o script da análise é carregado depois do pasta-metas.js')]
    public function testSemIaNoTextoEScriptCarregado(): void
    {
        $modal = $this->modal();
        self::assertStringNotContainsString('I.A', (string) $modal->filter('[data-analise-prazo]')->html());
        self::assertStringNotContainsString('I.A', (string) $modal->filter('.ps-ap-linha')->html());
        self::assertStringNotContainsString('I.A', (string) $modal->filter('.ps-ap-chip-wrap')->html());
    }
}
