<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Pasta\DTO\ExploradorDeDocumentosOutput;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * DOC-118 — a aba Documentos só oferece escrita a quem o servidor deixaria escrever: `podeEditar`
 * do `#pexDados` é `can_access_resource('pasta', id, 'edit')` E pasta fora da lápide, decidido no
 * `PastaController::show`. Sem ele, Nova pasta e Anexar nem saem do servidor, e o
 * `data-pode-editar` do `#pexExplorador` leva a mesma decisão ao checklist. O resto (o JS que não
 * liga menu, atalho, arraste) é provado por leitura de fonte em `PastaExploradorSomenteLeituraJsTest`.
 *
 * O servidor continua recusando — estes testes provam só a tela.
 */
#[CoversClass(PastaController::class)]
#[CoversClass(ExploradorDeDocumentosOutput::class)]
#[Group('pasta')]
final class PastaExploradorSomenteLeituraTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;
    use CriaUsuarioComPermissoesTrait;

    #[TestDox('só VIEW: podeEditar=false no #pexDados, data-pode-editar="0", e a faixa sem #pexNovaPasta, #pexAnexar e #pexFileInput; busca, Organizar e Visualizar ficam')]
    public function testSoLeitor(): void
    {
        $client     = static::createClient();
        [, $tenant] = $this->criarAdmin();
        $pasta      = $this->criarPasta($tenant);
        $leitor     = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'modules.pastas.view']);
        $this->em()->flush();

        $this->logarComTenant($client, $leitor, $tenant);
        $crawler = $this->abrir($client, $pasta);

        self::assertFalse($this->dados($crawler)['podeEditar']);
        self::assertFalse($this->dados($crawler)['podeVerLixeira'], 'DOC-118c: o GET da lixeira é de quem edita');
        self::assertSame('0', $crawler->filter('#pexExplorador')->attr('data-pode-editar'));
        self::assertCount(0, $crawler->filter('#pexNovaPasta'));
        self::assertCount(0, $crawler->filter('#pexAnexar'));
        self::assertCount(0, $crawler->filter('#pexFileInput'));
        // Leitura continua.
        self::assertCount(1, $crawler->filter('.pex-cab > .pex-busca > #pexBusca'));
        self::assertCount(1, $crawler->filter('.pex-cab #pexOrganizar'));
        self::assertCount(1, $crawler->filter('.pex-cab #pexVisualizar'));
    }

    #[TestDox('com EDIT: podeEditar=true, data-pode-editar="1", e Nova pasta, Anexar e o seletor de arquivo na faixa')]
    public function testEditor(): void
    {
        $client     = static::createClient();
        [, $tenant] = $this->criarAdmin();
        $pasta      = $this->criarPasta($tenant);
        $editor     = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'resources.pasta.edit', 'modules.pastas.view']);
        $this->em()->flush();

        $this->logarComTenant($client, $editor, $tenant);
        $crawler = $this->abrir($client, $pasta);

        self::assertTrue($this->dados($crawler)['podeEditar']);
        self::assertTrue($this->dados($crawler)['podeVerLixeira']);
        self::assertSame('1', $crawler->filter('#pexExplorador')->attr('data-pode-editar'));
        self::assertCount(1, $crawler->filter('.pex-cab > #pexNovaPasta'));
        self::assertCount(1, $crawler->filter('.pex-cab > #pexAnexar'));
        self::assertCount(1, $crawler->filter('.pex-cab > #pexFileInput'));
    }

    #[TestDox('pasta na lápide: nem o admin recebe escrita — podeEditar=false e sem Nova pasta/Anexar')]
    public function testPastaExcluida(): void
    {
        $client          = static::createClient();
        [$admin, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pasta->marcarExcluida($admin, new \DateTimeImmutable());
        $this->em()->flush();

        $this->logarComTenant($client, $admin, $tenant);
        $crawler = $this->abrir($client, $pasta);

        self::assertFalse($this->dados($crawler)['podeEditar']);
        self::assertSame('0', $crawler->filter('#pexExplorador')->attr('data-pode-editar'));
        self::assertSame('1', $crawler->filter('#pexExplorador')->attr('data-pasta-excluida'));
        self::assertCount(0, $crawler->filter('#pexNovaPasta, #pexAnexar'));
        // DOC-118c: quem edita ainda olha a lixeira da lápide.
        self::assertTrue($this->dados($crawler)['podeVerLixeira']);
    }

    /**
     * DOC-118c: `podeVerLixeira` é a mesma decisão do GET da lixeira — pedida ao servidor para
     * cada um dos quatro casos, a flag e o status têm de bater. Só-leitor numa lápide não recebe
     * a lixeira (antes via o botão e levava 403 sem aviso); quem edita a recebe, com ou sem lápide.
     *
     * @return iterable<string, array{list<string>, bool, bool}>
     */
    public static function casosDaLixeira(): iterable
    {
        $ver    = ['resources.pasta.view', 'modules.pastas.view'];
        $editar = ['resources.pasta.view', 'resources.pasta.edit', 'modules.pastas.view'];

        yield 'só leitor, pasta viva'   => [$ver, false, false];
        yield 'só leitor, lápide'       => [$ver, true, false];
        yield 'quem edita, pasta viva'  => [$editar, false, true];
        yield 'quem edita, lápide'      => [$editar, true, true];
    }

    /** @param list<string> $permissoes */
    #[DataProvider('casosDaLixeira')]
    #[TestDox('DOC-118c: podeVerLixeira no #pexDados = o GET da lixeira atender ($_dataName)')]
    public function testLixeiraSoParaQuemOGetAtende(array $permissoes, bool $lapide, bool $esperado): void
    {
        $client           = static::createClient();
        [$admin, $tenant] = $this->criarAdmin();
        $pasta            = $this->criarPasta($tenant);
        $usuario          = $this->criarUsuarioComPermissoes($tenant, $permissoes);
        if ($lapide) {
            $pasta->marcarExcluida($admin, new \DateTimeImmutable());
        }
        $this->em()->flush();

        $this->logarComTenant($client, $usuario, $tenant);
        $crawler = $this->abrir($client, $pasta);
        $dados   = $this->dados($crawler);

        self::assertSame($lapide ? '1' : '0', $crawler->filter('#pexExplorador')->attr('data-pasta-excluida'));
        self::assertArrayHasKey('podeVerLixeira', $dados, 'a flag é contrato do #pexDados');
        self::assertSame($esperado, $dados['podeVerLixeira']);
        self::assertNotSame('', $dados['urlLixeira'], 'a URL vem sempre; quem decide é a flag');

        // A flag diz a verdade sobre o servidor: o mesmo usuário pede a lista.
        $this->em()->clear();
        $client->request('GET', $dados['urlLixeira']);
        self::assertResponseStatusCodeSame($esperado ? 200 : 403);
    }

    // ----------------------------------------------------------------- helpers

    private function abrir(KernelBrowser $client, Pasta $pasta, string $query = ''): Crawler
    {
        $this->em()->clear();
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId() . $query);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** @return array<string, mixed> */
    private function dados(Crawler $crawler): array
    {
        $script = $crawler->filter('script#pexDados[type="application/json"]');
        self::assertCount(1, $script);

        $dados = json_decode($script->text(null, false), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($dados);
        self::assertArrayHasKey('podeEditar', $dados, 'a flag é contrato do #pexDados');

        return $dados;
    }
}
