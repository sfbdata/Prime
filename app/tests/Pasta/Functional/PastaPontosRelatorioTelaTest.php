<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Card "Pontos do relatório inicial" no trilho da aba Detalhes (desenho VS 18.10.26, dc L.2520)
 * e a pendência da aba que sai da mesma regra (dc L.5504). A regra em si está no unitário
 * (`PontosDoRelatorioInicialTest`); aqui se prova que a TELA mostra o resultado no lugar certo:
 * o card é filho direto do trilho, logo depois do Registro da pasta.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaPontosRelatorioTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;
    use CriaUsuarioComPermissoesTrait;

    private const CARD = '#detalhes > .ps-grade > .ps-trilho > .ps-registro-pasta + section.ps-pontos-relatorio';

    private function abrir(KernelBrowser $client, Pasta $pasta): Crawler
    {
        $this->em()->clear();
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function observar(Pasta $pasta, User $autor, Tenant $tenant, string $conteudo): void
    {
        $obs = new PastaObservacaoDetalhes();
        $obs->setPasta($pasta)->setAutor($autor)->setTenant($tenant)->setConteudo($conteudo);
        $this->em()->persist($obs);
        $this->em()->flush();
    }

    #[TestDox('sem observação: selo "3 pendentes", os três pontos em alerta, o botão Completar e a aba Detalhes pendente')]
    public function testVazio(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrir($client, $pasta);

        $card = $crawler->filter(self::CARD);
        self::assertCount(1, $card, 'o card é filho direto do trilho, logo depois do Registro da pasta');
        self::assertSame('Pontos do relatório inicial', trim($card->filter('.ps-pontos-relatorio-cab > h2')->text()));
        self::assertSame('3 pendentes', trim($card->filter('.ps-pontos-relatorio-cab > .ps-pontos-relatorio-selo.is-pendente')->text()));
        self::assertCount(3, $card->filter('.ps-pontos-relatorio-lista > li.is-falta'));
        self::assertStringContainsString('O que o cliente busca com a demanda', $card->filter('li[data-ponto="objetivos"]')->text());
        self::assertCount(1, $card->filter('section > button.js-ri-completar'));
        self::assertStringContainsString('Completar no relatório', $card->filter('.js-ri-completar')->text());

        $aba = $crawler->filter('#pastaTabs > #detalhes-tab.ps-aba--pend');
        self::assertCount(1, $aba);
        self::assertStringContainsString('relatório inicial de atendimento ainda não preenchido', (string) $aba->attr('title'));
    }

    #[TestDox('parcial: selo "2 pendentes", objetivo registrado, e a aba diz o que falta')]
    public function testParcial(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->observar($pasta, $user, $tenant, '<p>O cliente <strong>pretende</strong> anular o contrato.</p>');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrir($client, $pasta);

        $card = $crawler->filter(self::CARD);
        self::assertSame('2 pendentes', trim($card->filter('.ps-pontos-relatorio-selo')->text()));
        self::assertCount(1, $card->filter('li.is-ok[data-ponto="objetivos"]'));
        self::assertStringContainsString('Registrado no relatório', $card->filter('li[data-ponto="objetivos"]')->text());
        self::assertCount(1, $card->filter('li.is-falta[data-ponto="acordos"]'));
        self::assertSame(
            '["Acordos iniciais","Resultado esperado pelo cliente"]',
            $card->filter('.js-ri-completar')->attr('data-faltam'),
        );

        self::assertStringContainsString(
            'relatório inicial sem acordos iniciais e resultado esperado pelo cliente',
            (string) $crawler->filter('#pastaTabs > #detalhes-tab.ps-aba--pend')->attr('title'),
        );
    }

    #[TestDox('completo: selo "Completo", sem botão e sem pendência na aba Detalhes')]
    public function testCompleto(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->observar($pasta, $user, $tenant, '<p>Objetivos do cliente: rescisão.</p>');
        $this->observar($pasta, $user, $tenant, '<p>Ficou combinado o envio dos extratos. Expectativa: acordo.</p>');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrir($client, $pasta);

        $card = $crawler->filter(self::CARD);
        self::assertSame('Completo', trim($card->filter('.ps-pontos-relatorio-selo.is-completo')->text()));
        self::assertCount(3, $card->filter('.ps-pontos-relatorio-lista > li.is-ok'));
        self::assertCount(0, $card->filter('.js-ri-completar'));
        self::assertCount(0, $crawler->filter('#pastaTabs > #detalhes-tab.ps-aba--pend'));
    }

    #[TestDox('quem só vê a pasta vê o card e o selo, mas não o Completar (não há compositor onde escrever)')]
    public function testLeitorNaoTemCompletar(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $pasta  = $this->criarPasta($tenant);
        $leitor = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'modules.pastas.view']);
        $this->logarComTenant($client, $leitor, $tenant);

        $crawler = $this->abrir($client, $pasta);

        $card = $crawler->filter(self::CARD);
        self::assertCount(1, $card);
        self::assertSame('3 pendentes', trim($card->filter('.ps-pontos-relatorio-selo')->text()));
        self::assertCount(0, $card->filter('.js-ri-completar'));
        self::assertCount(0, $crawler->filter('#detalhesObsConteudo'), 'premissa: o leitor não tem compositor');
    }
}
