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

    private const CARD = '#detalhes > details.mdl-relatorio > .mdl-relatorio-corpo > .ps-grade > .ps-trilho > .ps-registro-pasta + section.ps-pontos-relatorio';

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

    #[TestDox('mais de 100 observações: o relatório inicial, a mais antiga, ainda conta (a lista da tela tem teto de 100)')]
    public function testRelatorioAlemDoTetoDaLista(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->observar($pasta, $user, $tenant, '<p>O cliente pretende anular o contrato.</p>');
        $this->em()->createQuery('UPDATE ' . PastaObservacaoDetalhes::class . ' o SET o.criadaEm = :antes WHERE o.pasta = :pasta')
            ->setParameter('antes', new \DateTimeImmutable('-1 year'))
            ->setParameter('pasta', $pasta)
            ->execute();
        for ($i = 0; $i < 100; ++$i) {
            $obs = new PastaObservacaoDetalhes();
            $obs->setPasta($pasta)->setAutor($user)->setTenant($tenant)->setConteudo('<p>Andamento ' . $i . '.</p>');
            $this->em()->persist($obs);
        }
        $this->em()->flush();
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrir($client, $pasta);

        self::assertCount(1, $crawler->filter(self::CARD . ' li.is-ok[data-ponto="objetivos"]'));
        self::assertSame('2 pendentes', trim($crawler->filter(self::CARD . ' .ps-pontos-relatorio-selo')->text()));
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

    // =========================================================================
    // Rota que o card usa para se redesenhar (pasta_detalhes_pontos_relatorio)
    // =========================================================================

    #[TestDox('a rota devolve itens, selo, completo e pendência do relatório inteiro')]
    public function testRotaDevolveOsPontos(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->observar($pasta, $user, $tenant, '<p>O cliente <em>pretende</em> anular o contrato.</p>');
        $this->logarComTenant($client, $user, $tenant);

        $client->xmlHttpRequest('GET', '/pasta/' . $pasta->getId() . '/detalhes/pontos-relatorio');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['itens', 'selo', 'completo', 'pendencia'], array_keys($data));
        self::assertSame('2 pendentes', $data['selo']);
        self::assertFalse($data['completo']);
        self::assertSame(['objetivos', 'acordos', 'resultado'], array_column($data['itens'], 'k'));
        self::assertSame([true, false, false], array_column($data['itens'], 'ok'));
        self::assertSame(['k', 'rot', 'ok', 'dica'], array_keys($data['itens'][0]));
        self::assertSame(
            ['n' => 2, 'txt' => 'relatório inicial sem acordos iniciais e resultado esperado pelo cliente'],
            $data['pendencia'],
        );

        $this->observar($pasta, $user, $tenant, '<p>Ficou combinado o envio. Expectativa: acordo.</p>');
        $client->xmlHttpRequest('GET', '/pasta/' . $pasta->getId() . '/detalhes/pontos-relatorio');
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Completo', $data['selo']);
        self::assertTrue($data['completo']);
        self::assertNull($data['pendencia']);
    }

    #[TestDox('sem permissão de ver a pasta (mesmo escritório): 403, como a pasta_show recusa')]
    public function testRotaRecusaSemPermissao(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $pasta  = $this->criarPasta($tenant);
        $semVer = $this->criarUsuarioComPermissoes($tenant, ['modules.pastas.view']);
        $this->logarComTenant($client, $semVer, $tenant);

        $client->xmlHttpRequest('GET', '/pasta/' . $pasta->getId() . '/detalhes/pontos-relatorio');

        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('itens', (string) $client->getResponse()->getContent());
    }

    #[TestDox('pasta de outro escritório: 404, sem nada do relatório (SUPER_ADMIN de propósito: só a posse pode recusar)')]
    public function testRotaRecusaOutroEscritorio(): void
    {
        $client             = static::createClient();
        [$dono, $tenantA]   = $this->criarAdmin();
        $pasta              = $this->criarPasta($tenantA);
        $this->observar($pasta, $dono, $tenantA, '<p>Objetivo sigiloso.</p>');
        [$intruso, $tenantB] = $this->criarAdmin();
        $this->logarComTenant($client, $intruso, $tenantB);

        $client->xmlHttpRequest('GET', '/pasta/' . $pasta->getId() . '/detalhes/pontos-relatorio');

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('itens', (string) $client->getResponse()->getContent());
    }
}
