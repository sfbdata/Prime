<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Twig\DocumentosSugeridosExtension;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * C6-DOC-01 — a regra do DOC-118 vale também no painel "Documentos sugeridos": "+ Checklist"
 * (`.ds-sug-add-um`) e "Adicionar N faltante(s) ao checklist" (`#btnDocumentosSugeridosFaltantes`)
 * gravam pelo `pasta_checklist_adicionar`, que exige editar a pasta e que a lápide recusa. Só saem
 * do servidor com `explorador.podeEditar`. O painel em si (leitura) continua para quem só vê.
 *
 * O servidor continua recusando — estes testes provam só a tela.
 */
#[CoversClass(DocumentosSugeridosExtension::class)]
#[Group('pasta')]
final class PastaDocumentosSugeridosSomenteLeituraTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;
    use CriaUsuarioComPermissoesTrait;

    #[TestDox('só VIEW: o painel aparece com as sugestões, mas sem "+ Checklist" e sem "Adicionar N faltante(s)"')]
    public function testSoLeitorNaoRecebeEscrita(): void
    {
        $client     = static::createClient();
        [, $tenant] = $this->criarAdmin();
        $pasta      = $this->criarPastaComAcao($tenant);
        $leitor     = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'modules.pastas.view']);
        $this->em()->flush();

        $this->logarComTenant($client, $leitor, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $painel = $crawler->filter('#documentosSugeridos');
        self::assertSame(1, $painel->count(), 'a leitura das sugestões continua');
        self::assertGreaterThan(0, $painel->filter('.ds-sug-grupo[data-status="req"] .ds-sug-item')->count(), 'premissa: há faltantes');
        self::assertSame(0, $painel->filter('.ds-sug-add-um')->count());
        self::assertSame(0, $painel->filter('#btnDocumentosSugeridosFaltantes')->count());
    }

    #[TestDox('pasta na lápide: nem o admin recebe "+ Checklist" e "Adicionar N faltante(s)"')]
    public function testLapideNaoRecebeEscrita(): void
    {
        $client           = static::createClient();
        [$admin, $tenant] = $this->criarAdmin();
        $pasta            = $this->criarPastaComAcao($tenant);
        $pasta->marcarExcluida($admin, new \DateTimeImmutable());
        $this->em()->flush();

        $this->logarComTenant($client, $admin, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $painel = $crawler->filter('#documentosSugeridos');
        self::assertSame(1, $painel->count(), 'premissa: o painel é renderizado na lápide');
        self::assertSame(0, $painel->filter('.ds-sug-add-um')->count());
        self::assertSame(0, $painel->filter('#btnDocumentosSugeridosFaltantes')->count());
    }

    #[TestDox('com EDIT: "+ Checklist" nos itens faltantes e "Adicionar N faltante(s)" no rodapé')]
    public function testEditorRecebeEscrita(): void
    {
        $client     = static::createClient();
        [, $tenant] = $this->criarAdmin();
        $pasta      = $this->criarPastaComAcao($tenant);
        $editor     = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'resources.pasta.edit', 'modules.pastas.view']);
        $this->em()->flush();

        $this->logarComTenant($client, $editor, $tenant);
        $crawler = $this->abrir($client, $pasta);

        self::assertGreaterThan(0, $crawler->filter('#documentosSugeridosCorpo > .ds-sug-grupo > .ds-sug-item > .ds-sug-add-um')->count());
        self::assertSame(1, $crawler->filter('#documentosSugeridosCorpo > .ds-sug-rodape > #btnDocumentosSugeridosFaltantes')->count());
    }

    // ----------------------------------------------------------------- helpers

    /** Ação com catálogo: a mesma do `PastaDocumentosSugeridosTest`, que tem 5 obrigatórios/recomendáveis. */
    private function criarPastaComAcao(Tenant $tenant): Pasta
    {
        $pasta = $this->criarPasta($tenant);
        $pasta->setNomeAcao('Ação de indenização por danos morais');
        $this->em()->flush();

        return $pasta;
    }

    private function abrir(KernelBrowser $client, Pasta $pasta): Crawler
    {
        $this->em()->clear();
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }
}
