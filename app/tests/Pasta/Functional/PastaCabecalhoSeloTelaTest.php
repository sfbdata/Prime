<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Cabeçalho da pasta, lote L1 do desenho 1.2.3: selo carimbado ARQUIVADO, item "Imprimir resumo"
 * no menu ⋮ e o campo da confirmação digitada no formulário de excluir.
 *
 * O que se prova é ARRANJO, com combinador de filho direto: o selo é filho da faixa de dados (onde
 * o desenho o ancora), não "algo em algum lugar da página". Aparência (giro, cor, granulado) segue
 * invisível para o PHPUnit — é do smoke do dono.
 */
#[Group('pasta')]
final class PastaCabecalhoSeloTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const SELO = '.ps-cabecalho > .ps-cab-dados > .ps-cab-selo';

    #[TestDox('Pasta arquivada mostra o selo ARQUIVADO como filho direto da faixa de dados')]
    public function testArquivadaMostraOSelo(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pasta->setSituacao('arquivado');
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        self::assertResponseIsSuccessful();
        $selo = $crawler->filter(self::SELO);
        self::assertCount(1, $selo, 'O selo precisa estar na faixa de dados do cabeçalho.');
        self::assertStringContainsString('ARQUIVADO', $selo->text());
        self::assertSame('img', $selo->attr('role'));
        self::assertCount(1, $selo->filter('.bi-archive-fill'), 'O ícone do carimbo é o do desenho.');
    }

    #[TestDox('Pasta ativa NÃO tem selo — nem ATIVO, nem Suspenso/Cancelado, que o sistema não conhece')]
    public function testAtivaNaoTemSelo(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        self::assertResponseIsSuccessful();
        self::assertSame('ativo', $pasta->getSituacao());
        self::assertCount(0, $crawler->filter('.ps-cab-selo'), 'Pasta ativa não pode ganhar carimbo.');
        // O modelo do selo para a troca ao vivo mora num atributo, não no DOM.
        self::assertNotEmpty($crawler->filter('.ps-cab-dados')->attr('data-ps-selo-modelo'));
    }

    #[TestDox('PAS-002: o carimbo ATIVO da reativação existe só como modelo (verde, bi-check-circle-fill) e nunca nasce no DOM')]
    public function testModeloDoSeloAtivo(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pasta->setSituacao('arquivado');
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.ps-cab-selo--ativo'), 'o ATIVO só aparece na troca ao vivo');
        self::assertCount(1, $crawler->filter(self::SELO . '.ps-cab-selo--arquivado'));

        $modelo = (string) $crawler->filter('.ps-cabecalho > .ps-cab-dados')->attr('data-ps-selo-ativo-modelo');
        $selo   = (new Crawler('<div>' . $modelo . '</div>'))->filter('div > .ps-cab-selo.ps-cab-selo--ativo[role="img"]');
        self::assertCount(1, $selo);
        self::assertSame('Pasta ativa', $selo->attr('aria-label'));
        self::assertSame('ATIVO', trim($selo->filter('.ps-cab-selo-faixa')->text()));
        self::assertCount(1, $selo->filter('.ps-cab-selo-faixa > i.bi-check-circle-fill'));
    }

    #[TestDox('O menu ⋮ oferece "Imprimir resumo", apontando para a rota de impressão desta pasta, em aba nova')]
    public function testMenuOfereceImprimirResumo(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        $item = $crawler->filter('#psMenuAcoes > a.ps-pop-item[href$="/pasta/' . $pasta->getId() . '/resumo/imprimir"]');
        self::assertCount(1, $item);
        self::assertStringContainsString('Imprimir resumo', $item->text());
        self::assertSame('_blank', $item->attr('target'));
    }

    #[TestDox('O formulário de excluir leva o número da pasta e o campo que o servidor confere')]
    public function testFormularioDeExcluirTemConfirmacao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        $form = $crawler->filter('#psMenuAcoes > form.js-excluir-pasta[action$="/deletar"]');
        self::assertCount(1, $form);
        self::assertSame($pasta->getNup(), $form->attr('data-nup'));
        self::assertCount(1, $form->filter('input[type="hidden"][name="confirmar_nup"]'));
        self::assertStringContainsString('prompt(', (string) $form->attr('onsubmit'));
    }
}
