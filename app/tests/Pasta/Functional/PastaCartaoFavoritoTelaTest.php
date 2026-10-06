<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaFavorita;
use App\Entity\Tenant\Tenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Estrela de favorito no CARTÃO do Expediente (modo lista no desktop e sempre no celular —
 * `pasta/_card.html.twig`, item 7 das pendências pós-Documentos).
 *
 * Arranjo com combinador de filho direto: a estrela é botão DENTRO do bloco de identificação do
 * cartão, antes do NUP — não "existe em algum lugar da página" (a tabela, que coexiste no DOM,
 * tem a sua própria estrela). O estado (`aria-pressed`) é o do USUÁRIO LOGADO: o favorito de um
 * colega na mesma pasta não liga a minha estrela.
 */
#[Group('pasta')]
final class PastaCartaoFavoritoTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const SELETOR_ESTRELA = '.pastas-lista > .pasta-card > .pasta-card-topo > .pasta-card-ident > button.js-pasta-card-favorito';

    #[TestDox('O cartão traz a estrela antes do NUP: ligada na minha favorita, desligada na do colega e na comum')]
    public function testEstrelaDoCartaoSegueOFavoritoDoUsuarioLogado(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $colega          = $this->criarUsuarioSemPermissaoDoModulo($tenant);
        $minha           = $this->criarPastaComNumero($tenant, '92001');
        $comum           = $this->criarPastaComNumero($tenant, '92002');
        $doColega        = $this->criarPastaComNumero($tenant, '92003');
        $this->em()->persist(new PastaFavorita($tenant, $user, $minha));
        $this->em()->persist(new PastaFavorita($tenant, $colega, $doColega));
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/expediente/painel/acervo-geral', ['view' => 'lista'], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        self::assertCount(3, $crawler->filter(self::SELETOR_ESTRELA), 'uma estrela por cartão, no bloco de identificação');

        $estrelaMinha = $this->estrelaDoCartao($crawler, $minha);
        self::assertSame('true', $estrelaMinha->attr('aria-pressed'));
        self::assertStringContainsString('is-favorita', (string) $estrelaMinha->attr('class'));
        self::assertCount(1, $estrelaMinha->filter('i.bi-star-fill'));
        self::assertSame('Tirar dos favoritos', $estrelaMinha->attr('aria-label'));
        self::assertStringEndsWith('/pasta/' . $minha->getId() . '/favorito', (string) $estrelaMinha->attr('data-url'));
        self::assertNotSame('', (string) $estrelaMinha->attr('data-csrf'));

        $estrelaDoColega = $this->estrelaDoCartao($crawler, $doColega);
        self::assertSame('false', $estrelaDoColega->attr('aria-pressed'), 'o favorito do colega não liga a minha estrela');
        self::assertStringNotContainsString('is-favorita', (string) $estrelaDoColega->attr('class'));
        self::assertCount(0, $estrelaDoColega->filter('i.bi-star-fill'));
        self::assertCount(1, $estrelaDoColega->filter('i.bi-star'));
        self::assertSame('Marcar como favorito', $estrelaDoColega->attr('aria-label'));

        $estrelaComum = $this->estrelaDoCartao($crawler, $comum);
        self::assertSame('false', $estrelaComum->attr('aria-pressed'));
    }

    #[TestDox('A estrela vem ANTES do NUP no bloco de identificação do cartão')]
    public function testEstrelaVemAntesDoNup(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaComNumero($tenant, '92101');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/expediente/painel/acervo-geral', [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $ident = $crawler->filter('.pastas-lista > .pasta-card > .pasta-card-topo > .pasta-card-ident');
        self::assertCount(1, $ident);
        $filhos = $ident->children()->each(fn (Crawler $c) => (string) $c->attr('class'));
        self::assertStringContainsString('js-pasta-card-favorito', $filhos[0]);
        self::assertStringContainsString('pasta-card-nup', $filhos[1]);
        self::assertStringContainsString($pasta->getNup(), $ident->filter('.pasta-card-nup')->text());
    }

    #[TestDox('Sem favorito nenhum do usuário, nenhuma estrela do cartão nasce ligada — nem pela favorita de outro escritório')]
    public function testFavoritaDeOutroEscritorioNaoLigaEstrela(): void
    {
        $client            = static::createClient();
        [$userA, $tenantA] = $this->criarAdmin();
        [$userB, $tenantB] = $this->criarAdmin();
        $this->criarPastaComNumero($tenantA, '92201');
        $pastaB = $this->criarPastaComNumero($tenantB, '92202');
        $this->em()->persist(new PastaFavorita($tenantB, $userB, $pastaB));
        $this->em()->flush();

        $this->logarComTenant($client, $userA, $tenantA);
        $crawler = $client->request('GET', '/expediente/painel/acervo-geral', [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $estrelas = $crawler->filter(self::SELETOR_ESTRELA);
        self::assertCount(1, $estrelas, 'só o cartão da pasta do meu escritório');
        self::assertSame('false', $estrelas->attr('aria-pressed'));
        self::assertCount(0, $crawler->filter('.pastas-lista button.js-pasta-card-favorito[aria-pressed="true"]'));
        self::assertStringNotContainsString('/pasta/' . $pastaB->getId() . '/favorito', (string) $client->getResponse()->getContent());
    }

    private function estrelaDoCartao(Crawler $crawler, Pasta $pasta): Crawler
    {
        $estrela = $crawler->filter(self::SELETOR_ESTRELA . '[data-pasta-id="' . $pasta->getId() . '"]');
        self::assertCount(1, $estrela, 'cartão da pasta ' . $pasta->getNup() . ' com a estrela');

        return $estrela;
    }

    private function criarPastaComNumero(Tenant $tenant, string $nup): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup($nup);
        $pasta->setTenant($tenant);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }
}
