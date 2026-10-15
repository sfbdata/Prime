<?php

declare(strict_types=1);

namespace App\Tests\Central\Functional;

use App\Central\Twig\CentralExtension;
use App\Tests\Central\Support\CentralHttpTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Lançador flutuante da Central (Designer 13/10, bluejus-central.js L5412/L6399): entra no layout de quem tem vínculo
 * ativo, em toda página menos a própria /central; monta a MESMA janela da Central (um central.js só, sem segundo
 * backend); `central_disponivel()` nunca derruba a página.
 *
 * Arranjo com combinador de filho direto (CLAUDE.md: "está ao lado" × "existe em algum lugar").
 */
#[CoversClass(CentralExtension::class)]
final class CentralFlutuanteTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;
    use CentralHttpTrait;

    #[TestDox('Página comum: o lançador (3 desenhos, contador, "@n") e a janela flutuante com a Central em modo flutuante')]
    public function testLancadorNaPaginaComum(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $this->logarComTenant($client, $ana, $tenant);

        $client->request('GET', '/notificacoes');
        self::assertResponseIsSuccessful();

        self::assertSelectorExists('body [data-central-flutuante][data-eu-id="' . $ana->getId() . '"] > [data-flutuante-lancador][data-estado="calmo"][aria-expanded="false"]');
        foreach (['calmo', 'aberto', 'novas'] as $estado) {
            self::assertSelectorExists('[data-flutuante-lancador] > .bj-flutuante-icone > svg.bj-flutuante-svg-' . $estado);
        }
        self::assertSelectorExists('[data-flutuante-lancador] > [data-flutuante-badge][hidden]');
        self::assertSelectorExists('[data-flutuante-lancador] > [data-flutuante-mencoes][hidden]');

        // A janela é a da Central, em modo flutuante, fechada até o clique; botões de janela no cabeçalho.
        self::assertSelectorExists('[data-flutuante-janela][hidden] > [data-central][data-modo="flutuante"]');
        foreach (['minimizar', 'maximizar', 'fechar'] as $botao) {
            self::assertSelectorExists('[data-central][data-modo="flutuante"] > .bj-central-cab > .bj-central-janela-acoes > [data-flutuante-' . $botao . ']');
        }
        self::assertSelectorExists('[data-central-tela-lista] > .bj-central-abas > [data-central-aba="entrada"] + [data-central-aba="painel"]');
        self::assertSelectorExists('[data-central-tela-lista] > [data-central-filtros] > [data-central-filtro="mencoes"]');
        self::assertCount(1, $client->getCrawler()->filter('[data-central]'), 'uma Central só na página');
        self::assertCount(1, $client->getCrawler()->filter('script[src$="/js/central.js"]'));
        self::assertCount(1, $client->getCrawler()->filter('script[src$="/js/central-flutuante.js"]'));
    }

    #[TestDox('Na própria /central não há lançador: a página já é a janela (modo página, sem botões de janela)')]
    public function testSemLancadorNaCentral(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $this->logarComTenant($client, $ana, $tenant);

        $client->request('GET', '/central');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-central-flutuante]');
        self::assertSelectorNotExists('[data-flutuante-janela]');
        self::assertSelectorExists('[data-central][data-modo="pagina"]');
        self::assertSelectorNotExists('.bj-central-janela-acoes');
        self::assertCount(1, $client->getCrawler()->filter('[data-central]'));
    }

    #[TestDox('central_disponivel() sem usuário logado é false — e não lança (roda em toda página do layout)')]
    public function testDisponivelSemUsuario(): void
    {
        self::bootKernel();
        /** @var CentralExtension $extensao */
        $extensao = static::getContainer()->get(CentralExtension::class);

        self::assertFalse($extensao->disponivel());
    }
}
