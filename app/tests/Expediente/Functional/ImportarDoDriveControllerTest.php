<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Functional;

use App\Expediente\Controller\ImportarDoDriveController;
use App\Expediente\Controller\ImportarLoteController;
use App\Pasta\Controller\PeticionarController;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Sync\Entity\TenantDriveConexao;
use App\Sync\Exception\ImportacaoDoDriveRecusadaException;
use App\Sync\Service\CifradorDeSegredo;
use App\Sync\Service\GoogleDriveClientFactoryInterface;
use App\Sync\UseCase\LigarPastaImportadaDoDriveUseCase;
use App\Tests\Sync\Support\FakeGoogleDriveClient;
use App\Tests\Sync\Support\FakeGoogleDriveClientFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * "Importar do Google Drive" (spec importar-do-drive.md): árvore da pasta do Drive, envio de cada arquivo pelo
 * mesmo upload da aba Documentos e a pasta criada ligada à pasta de origem. Drive simulado; raiz "ROOT".
 */
#[CoversClass(ImportarDoDriveController::class)]
#[CoversClass(ImportarLoteController::class)]
#[CoversClass(PeticionarController::class)]
#[CoversClass(LigarPastaImportadaDoDriveUseCase::class)]
final class ImportarDoDriveControllerTest extends BuscaRapidaWebTestCase
{
    private const ARVORE = '/expediente/importar-lote/drive/arvore';
    private const CRIAR  = '/expediente/importar-lote/pasta';

    private FakeGoogleDriveClient $fake;

    /** Admin logado, com o Drive simulado: ROOT/Clientes/ANA/{procuracao.pdf, Contrato (Doc)} e uma pasta pessoal fora. */
    private function preparar(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $tenant = $this->criarTenant();
        $this->logarComTenant($client, $this->criarAdministrador($tenant), $tenant);

        $this->fake = new FakeGoogleDriveClient();
        $this->fake->seedPasta('ROOT', 'Escritório', '');
        $this->fake->seedPasta('pasta_clientes', 'Clientes', 'ROOT');
        $this->fake->seedPasta('pasta_ana_01', 'ANA', 'pasta_clientes');
        $this->fake->seedArquivo('arquivo_procuracao', 'procuracao.pdf', 'pasta_ana_01');
        $this->fake->seedArquivo('arquivo_contrato', 'Contrato', 'pasta_ana_01', 0, 'application/vnd.google-apps.document');
        $this->fake->seedPasta('pasta_pessoal', 'Pessoal', 'OUTRA_RAIZ');
        $this->fake->seedArquivo('arquivo_pessoal', 'extrato.pdf', 'pasta_pessoal');
        self::getContainer()->set(GoogleDriveClientFactoryInterface::class, new FakeGoogleDriveClientFactory($this->fake, 'ROOT'));

        return $client;
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    private function tokenDaTela(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/expediente');
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('#il-raiz')->attr('data-token');
    }

    /** @return array<string, mixed> a pasta criada pelo lote, ligada a $driveFolderId */
    private function criarPastaLigada(KernelBrowser $client, string $driveFolderId): array
    {
        $client->request('POST', self::CRIAR, ['nome_cliente' => 'ANA', '_token' => $this->tokenDaTela($client), 'drive_folder_id' => $driveFolderId]);

        return $this->json($client);
    }

    #[TestDox('tela: o botão "Importar do Google Drive" e a entrada do modal só aparecem com o Drive do escritório conectado')]
    public function testBotaoSoComDriveConectado(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $tenant = $this->criarTenant();
        $admin  = $this->criarAdministrador($tenant);
        $this->logarComTenant($client, $admin, $tenant);

        $crawler = $client->request('GET', '/expediente');
        self::assertCount(0, $crawler->filter('.exp-nova-rodape > [data-acao="importar-drive"]'));
        self::assertNull($crawler->filter('#il-raiz')->attr('data-url-arvore'));

        $conexao = new TenantDriveConexao($tenant);
        $conexao->registrarCredenciais(self::getContainer()->get(CifradorDeSegredo::class)->cifrar('tok'), 'a@b.com', 'drive', $admin);
        $conexao->definirRootFolder('root-abcdefghij');
        $this->em()->persist($conexao);
        $this->em()->flush();

        $crawler = $client->request('GET', '/expediente');
        self::assertCount(1, $crawler->filter('.exp-nova-rodape > [data-acao="importar-lote"] + [data-acao="importar-drive"]'), 'ao lado do Importar em lote');
        self::assertSame(self::ARVORE, $crawler->filter('#il-raiz')->attr('data-url-arvore'));
        self::assertCount(1, $crawler->filter('#il-raiz [data-il="drive"] [data-il="drive-link"]'));
    }

    #[TestDox('árvore: sem o módulo Pastas (a permissão de criar pasta), 403')]
    public function testArvoreSemModuloPastasDa403(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->logarComTenant($client, $this->criarUsuarioComPermissoes($tenant, []), $tenant);

        $client->request('GET', self::ARVORE, ['pasta' => 'pasta_clientes']);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('árvore: escritório sem Drive conectado, 409 com a mensagem')]
    public function testArvoreSemDriveConectado(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->logarComTenant($client, $this->criarAdministrador($tenant), $tenant);

        $client->request('GET', self::ARVORE, ['pasta' => 'pasta_clientes']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(ImportarDoDriveController::SEM_DRIVE, $this->json($client)['mensagem']);
    }

    #[TestDox('árvore: devolve os caminhos do lote, o Doc com .docx e o id de cada pasta')]
    public function testArvoreDevolveOsCaminhos(): void
    {
        $client = $this->preparar();

        $client->request('GET', self::ARVORE, ['pasta' => 'https://drive.google.com/drive/folders/pasta_clientes']);

        self::assertResponseIsSuccessful();
        $arvore = $this->json($client);
        $caminhos = array_column($arvore['itens'], 'caminho');
        sort($caminhos);
        self::assertSame(['Clientes/ANA/Contrato.docx', 'Clientes/ANA/procuracao.pdf'], $caminhos);
        self::assertSame('pasta_ana_01', array_column($arvore['pastas'], 'id', 'caminho')['Clientes/ANA']);
    }

    #[TestDox('árvore: pasta fora da pasta do escritório, 422 com a mensagem')]
    public function testArvoreForaDaRaiz(): void
    {
        $client = $this->preparar();

        $client->request('GET', self::ARVORE, ['pasta' => 'pasta_pessoal']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(ImportacaoDoDriveRecusadaException::FORA_DA_RAIZ, $this->json($client)['mensagem']);
    }

    #[TestDox('criar pasta ligada a uma pasta fora da pasta do escritório: 422 e nenhuma pasta criada')]
    public function testCriarPastaForaDaRaizNaoCria(): void
    {
        $client = $this->preparar();
        $antes  = $this->em()->getRepository(Pasta::class)->count([]);

        $criada = $this->criarPastaLigada($client, 'pasta_pessoal');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(ImportacaoDoDriveRecusadaException::FORA_DA_RAIZ, $criada['mensagem']);
        self::assertSame($antes, $this->em()->getRepository(Pasta::class)->count([]));
    }

    #[TestDox('a pasta criada fica ligada à de origem (sem renomear) e cada arquivo ao arquivo de origem; o Doc entra como .docx')]
    public function testPastaEArquivosLigadosAoDrive(): void
    {
        $client = $this->preparar();
        $criada = $this->criarPastaLigada($client, 'pasta_ana_01');
        self::assertResponseIsSuccessful();

        $pasta = $this->em()->find(Pasta::class, $criada['id']);
        self::assertNotNull($pasta);
        self::assertSame('/pasta/' . $criada['id'] . '/peticionar/upload-drive', $criada['uploadDriveUrl']);
        self::assertSame('pasta_ana_01', $pasta->getDriveFolderId());
        self::assertTrue($pasta->isDriveManterNome());

        $url = '/pasta/' . $criada['id'] . '/peticionar/upload-drive';
        foreach (['arquivo_procuracao', 'arquivo_contrato'] as $id) {
            $client->request('POST', $url, ['_token' => $criada['uploadToken'], 'categoria' => 'DEMAIS', 'drive_file_id' => $id], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
            self::assertResponseIsSuccessful();
            self::assertTrue($this->json($client)['success']);
        }

        $docs = $this->em()->getRepository(PastaDocumento::class)->findBy(['pasta' => $pasta], ['id' => 'ASC']);
        self::assertSame(['procuracao.pdf', 'Contrato.docx'], array_map(static fn (PastaDocumento $d): string => (string) $d->getNomeOriginal(), $docs));
        self::assertSame(['arquivo_procuracao', 'arquivo_contrato'], array_map(static fn (PastaDocumento $d): ?string => $d->getDriveFileId(), $docs));
        self::assertCount(1, $this->fake->exportacoes);
        self::assertSame([], $this->fake->renomeacoes);
    }

    #[TestDox('o mesmo arquivo do Drive importado de novo entra como cópia, sem vínculo')]
    public function testSegundaImportacaoEntraComoCopia(): void
    {
        $client  = $this->preparar();
        $primeira = $this->criarPastaLigada($client, 'pasta_ana_01');
        $segunda  = $this->criarPastaLigada($client, 'pasta_ana_01');

        $segundaPasta = $this->em()->find(Pasta::class, $segunda['id']);
        self::assertNotNull($segundaPasta);
        self::assertNull($segundaPasta->getDriveFolderId(), 'a pasta do Drive já está ligada à primeira');

        foreach ([$primeira, $segunda] as $criada) {
            $client->request('POST', '/pasta/' . $criada['id'] . '/peticionar/upload-drive', ['_token' => $criada['uploadToken'], 'categoria' => 'DEMAIS', 'drive_file_id' => 'arquivo_procuracao'], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
            self::assertResponseIsSuccessful();
        }
        $doc = $this->em()->getRepository(PastaDocumento::class)->findOneBy(['pasta' => $segundaPasta]);
        self::assertNotNull($doc);
        self::assertNull($doc->getDriveFileId());
    }

    #[TestDox('envio: arquivo fora da pasta do escritório, 422 e nada anexado')]
    public function testEnvioForaDaRaiz(): void
    {
        $client = $this->preparar();
        $criada = $this->criarPastaLigada($client, 'pasta_ana_01');

        $client->request('POST', '/pasta/' . $criada['id'] . '/peticionar/upload-drive', ['_token' => $criada['uploadToken'], 'drive_file_id' => 'arquivo_pessoal'], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(ImportacaoDoDriveRecusadaException::FORA_DA_RAIZ, $this->json($client)['error']);
        self::assertSame([], $this->fake->destinosDeDownload);
        self::assertSame(0, $this->em()->getRepository(PastaDocumento::class)->count(['pasta' => $criada['id']]));
    }

    #[TestDox('envio: token errado, 403 e nada baixado')]
    public function testEnvioTokenErrado(): void
    {
        $client = $this->preparar();
        $criada = $this->criarPastaLigada($client, 'pasta_ana_01');

        $client->request('POST', '/pasta/' . $criada['id'] . '/peticionar/upload-drive', ['_token' => 'falso', 'drive_file_id' => 'arquivo_procuracao'], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->fake->destinosDeDownload);
    }
}
