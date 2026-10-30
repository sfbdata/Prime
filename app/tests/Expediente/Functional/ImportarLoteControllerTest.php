<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Functional;

use App\Expediente\Controller\ImportarLoteController;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * "Importar pastas em lote" (spec importar-pastas-em-lote.md): a rota cria UMA pasta e devolve o
 * endereço e o token do upload da aba Documentos, que o navegador usa arquivo a arquivo.
 */
#[CoversClass(ImportarLoteController::class)]
final class ImportarLoteControllerTest extends BuscaRapidaWebTestCase
{
    private const URL = '/expediente/importar-lote/pasta';

    /** @var list<string> */
    private array $temporarios = [];

    protected function tearDown(): void
    {
        foreach ($this->temporarios as $arquivo) {
            @unlink($arquivo);
        }
        parent::tearDown();
    }

    /** Abre o Expediente e devolve o token que o modal entrega ao JS. */
    private function tokenDaTela(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/expediente');
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('#il-raiz')->attr('data-token');
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    #[TestDox('sem o módulo Pastas: 403 e nenhuma pasta criada')]
    public function testSemModuloPastasDa403(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuarioComPermissoes($tenant, []);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', self::URL, ['nome_cliente' => 'MARIA', '_token' => 'x']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->em()->getRepository(Pasta::class)->count(['tenant' => $tenant]));
    }

    #[TestDox('token inválido: 403 e nenhuma pasta criada')]
    public function testTokenInvalidoDa403(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin  = $this->criarAdministrador($tenant);

        $this->logarComTenant($client, $admin, $tenant);
        $client->request('POST', self::URL, ['nome_cliente' => 'MARIA', '_token' => 'falso']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->em()->getRepository(Pasta::class)->count(['tenant' => $tenant]));
    }

    #[TestDox('nome do cliente vazio: 422 com o texto do desenho')]
    public function testNomeVazioDa422(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $tenant = $this->criarTenant();
        $admin  = $this->criarAdministrador($tenant);

        $this->logarComTenant($client, $admin, $tenant);
        $token = $this->tokenDaTela($client);
        $client->request('POST', self::URL, ['nome_cliente' => '   ', '_token' => $token]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Todas as pastas marcadas precisam de um nome de cliente.', $this->json($client)['mensagem']);
    }

    #[TestDox('cria a pasta com o número do escritório e o token do upload anexa o arquivo em Demais documentos')]
    public function testCriaPastaEAnexaArquivo(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $tenant = $this->criarTenant();
        $admin  = $this->criarAdministrador($tenant);

        $this->logarComTenant($client, $admin, $tenant);
        $token = $this->tokenDaTela($client);

        $client->request('POST', self::URL, ['nome_cliente' => 'maria da silva', '_token' => $token]);
        self::assertResponseIsSuccessful();
        $criada = $this->json($client);

        self::assertSame('ok', $criada['status']);
        self::assertSame('MARIA DA SILVA', $criada['cliente']);
        self::assertSame('1', $criada['nup']);
        self::assertSame('/pasta/' . $criada['id'] . '/peticionar/upload', $criada['uploadUrl']);
        self::assertNotEmpty($criada['uploadToken']);

        $pasta = $this->em()->find(Pasta::class, $criada['id']);
        self::assertNotNull($pasta);
        self::assertSame($tenant->getId(), $pasta->getTenant()->getId());

        $tmp = (string) tempnam(sys_get_temp_dir(), 'lote_pdf_');
        file_put_contents($tmp, '%PDF-1.4 dummy');
        $this->temporarios[] = $tmp;
        $client->request(
            'POST',
            $criada['uploadUrl'],
            ['_token' => $criada['uploadToken'], 'categoria' => 'DEMAIS'],
            ['arquivo' => new UploadedFile($tmp, 'procuracao.pdf', 'application/pdf', null, true)],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );

        self::assertResponseIsSuccessful();
        self::assertTrue($this->json($client)['success']);
        $docs = $this->em()->getRepository(PastaDocumento::class)->findBy(['pasta' => $pasta]);
        self::assertCount(1, $docs);
        self::assertSame(PastaDocumento::CATEGORIA_DEMAIS, $docs[0]->getCategoria());
    }

    #[TestDox('pastas seguidas recebem números seguidos')]
    public function testNumerosSeguidos(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $tenant = $this->criarTenant();
        $admin  = $this->criarAdministrador($tenant);

        $this->logarComTenant($client, $admin, $tenant);
        $token = $this->tokenDaTela($client);

        $nups = [];
        foreach (['JOAO SOUZA', 'MARIA DA SILVA'] as $nome) {
            $client->request('POST', self::URL, ['nome_cliente' => $nome, '_token' => $token]);
            $nups[] = $this->json($client)['nup'];
        }

        self::assertSame(['1', '2'], $nups);
    }

    #[TestDox('nome do cliente com mais de 255 caracteres: 422, sem erro de banco')]
    public function testNomeLongoDemaisDa422(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $tenant = $this->criarTenant();
        $admin  = $this->criarAdministrador($tenant);

        $this->logarComTenant($client, $admin, $tenant);
        $token = $this->tokenDaTela($client);
        $client->request('POST', self::URL, ['nome_cliente' => str_repeat('A', 256), '_token' => $token]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->em()->getRepository(Pasta::class)->count(['tenant' => $tenant]));
    }

    #[TestDox('com o módulo Pastas mas sem editar pasta: cria a pasta e não entrega o token do upload')]
    public function testSemEditarNaoEntregaUpload(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuarioComPermissoes($tenant, ['modules.expediente.view', 'modules.pastas.view', 'resources.pasta.view']);

        $this->logarComTenant($client, $user, $tenant);
        $token = $this->tokenDaTela($client);
        $client->request('POST', self::URL, ['nome_cliente' => 'JOAO SOUZA', '_token' => $token]);

        self::assertResponseIsSuccessful();
        $criada = $this->json($client);
        self::assertSame('ok', $criada['status']);
        self::assertNull($criada['uploadUrl']);
        self::assertNull($criada['uploadToken']);
    }

    #[TestDox('o número segue o escritório: pasta de outro escritório não conta')]
    public function testNumeracaoPorEscritorio(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $outro = $this->criarTenant();
        $this->criarPasta($outro);
        $this->criarPasta($outro);
        $tenant = $this->criarTenant();
        $admin  = $this->criarAdministrador($tenant);

        $this->logarComTenant($client, $admin, $tenant);
        $token = $this->tokenDaTela($client);
        $client->request('POST', self::URL, ['nome_cliente' => 'MARIA', '_token' => $token]);

        self::assertSame('1', $this->json($client)['nup']);
    }

    #[TestDox('sem o módulo Pastas, a tela do Expediente não traz o modal do lote')]
    public function testTelaSemModuloPastasNaoTrazOModal(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuarioComPermissoes($tenant, ['modules.expediente.view', 'resources.pasta.view']);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/expediente');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#il-raiz'));
    }

    #[TestDox('a tela do Expediente traz o modal do desenho e o script do lote')]
    public function testTelaTemModalDoDesenho(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin  = $this->criarAdministrador($tenant);

        $this->logarComTenant($client, $admin, $tenant);
        $crawler = $client->request('GET', '/expediente');

        $modal = $crawler->filter('#il-raiz[hidden] .il-centro > .il-caixa[role="dialog"]');
        self::assertCount(1, $modal);
        self::assertSame('Importar pastas em lote', trim($modal->filter('.il-topo > b')->text()));
        self::assertCount(1, $modal->filter('.il-corpo .il-soltar > input[type="file"][webkitdirectory]'));
        self::assertStringContainsString('Selecionar a pasta dos clientes', $modal->text());
        self::assertStringContainsString('Ou selecionar arquivos soltos (vira uma pasta só)', $modal->text());
        self::assertCount(1, $crawler->filter('script[src*="js/expediente-importar-lote.js"]'));
    }
}
