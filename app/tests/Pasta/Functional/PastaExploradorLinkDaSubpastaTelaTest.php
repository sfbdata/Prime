<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaSecao;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * DOC-120 — o "Copiar link" de uma subpasta gera `pasta_show?pex=<secaoId>#documentos`, e o
 * explorador abre esse nível ao carregar. Quem decide se a seção vale é o `#pexDados`: o JS só
 * entra num id que está em `pastas` (provado por leitura de fonte em
 * `PastaExploradorLinkDaSubpastaJsTest`). Aqui a prova é do lado do servidor: a página abre
 * normalmente com qualquer `?pex`, e uma seção de OUTRA pasta (mesmo escritório) ou de OUTRO
 * escritório não entra no `#pexDados` nem na página — então é ignorada em silêncio.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaExploradorLinkDaSubpastaTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    #[TestDox('?pex de uma subpasta DESTA pasta: a página abre e a seção está no #pexDados; o explorador sabe a URL da pasta_show')]
    public function testSecaoDaPasta(): void
    {
        $client           = static::createClient();
        [$admin, $tenant] = $this->criarAdmin();
        $pasta            = $this->criarPasta($tenant);
        $secao            = $this->criarSecao($pasta, $tenant, 'Provas');
        $this->logarComTenant($client, $admin, $tenant);

        $crawler = $this->abrir($client, $pasta, '?pex=' . $secao->getId());

        self::assertContains((int) $secao->getId(), $this->idsDasPastas($crawler));
        self::assertSame('/pasta/' . $pasta->getId(), $crawler->filter('#pexExplorador')->attr('data-url-pasta'));
    }

    #[TestDox('?pex de subpasta de OUTRA pasta do escritório ou de OUTRO escritório: a página abre (200) e a seção não aparece — nem no #pexDados, nem pelo nome')]
    public function testSecaoDeOutraPastaEIgnorada(): void
    {
        $client           = static::createClient();
        [$admin, $tenant] = $this->criarAdmin();
        [, $outro]        = $this->criarAdmin();
        $pasta            = $this->criarPasta($tenant);
        $minha            = $this->criarSecao($pasta, $tenant, 'Minha');
        $irma             = $this->criarSecao($this->criarPasta($tenant), $tenant, 'Da Irma Secreta');
        $alheia           = $this->criarSecao($this->criarPasta($outro), $outro, 'Alheia Secreta');
        $this->logarComTenant($client, $admin, $tenant);

        foreach ([$irma, $alheia] as $secao) {
            $crawler = $this->abrir($client, $pasta, '?pex=' . $secao->getId());
            $ids     = $this->idsDasPastas($crawler);

            self::assertNotContains((int) $secao->getId(), $ids, 'seção de fora não entra nos dados do explorador');
            self::assertContains((int) $minha->getId(), $ids, 'a pasta abre com os níveis dela');
            self::assertStringNotContainsString((string) $secao->getNome(), (string) $client->getResponse()->getContent());
        }
    }

    #[TestDox('?pex que não é id (texto, zero, negativo, lista): a página abre normalmente')]
    public function testPexInvalidoNaoQuebra(): void
    {
        $client           = static::createClient();
        [$admin, $tenant] = $this->criarAdmin();
        $pasta            = $this->criarPasta($tenant);
        $this->logarComTenant($client, $admin, $tenant);

        foreach (['?pex=abc', '?pex=0', '?pex=-3', '?pex[]=1', '?pex=%3Cscript%3E'] as $query) {
            $crawler = $this->abrir($client, $pasta, $query);
            self::assertCount(1, $crawler->filter('script#pexDados'), "a aba Documentos sai inteira com {$query}");
            self::assertStringNotContainsString('<script>', (string) $crawler->filter('#pexExplorador')->attr('data-url-pasta'));
        }
    }

    // ----------------------------------------------------------------- helpers

    private function criarSecao(Pasta $pasta, Tenant $tenant, string $nome): PastaSecao
    {
        $secao = new PastaSecao();
        $secao->setPasta($pasta);
        $secao->setTenant($tenant);
        $secao->setNome($nome);
        $secao->setOrdem(1);
        $this->em()->persist($secao);
        $this->em()->flush();

        return $secao;
    }

    private function abrir(KernelBrowser $client, Pasta $pasta, string $query): Crawler
    {
        $this->em()->clear();
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId() . $query);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** @return list<int> */
    private function idsDasPastas(Crawler $crawler): array
    {
        $dados = json_decode($crawler->filter('script#pexDados')->text(null, false), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($dados);

        return array_map(static fn (array $p): int => (int) $p['id'], $dados['pastas']);
    }
}
