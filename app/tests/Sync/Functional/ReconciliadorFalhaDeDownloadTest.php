<?php

declare(strict_types=1);

namespace App\Tests\Sync\Functional;

use App\Shared\Service\ArquivoStorageInterface;
use App\Sync\Command\ReconciliarCommand;
use App\Sync\DTO\ResultadoReconciliacaoPasta;
use App\Sync\Enum\ModoSincronizacao;
use App\Sync\Service\GoogleDriveClient;
use App\Sync\Service\GoogleDriveClientFactoryInterface;
use App\Sync\Service\ReconciliadorDePasta;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Sync\Support\DriveHttpFalso;
use App\Tests\Sync\Support\FakeGoogleDriveClient;
use App\Tests\Sync\Support\FakeGoogleDriveClientFactory;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Http\Message\RequestInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

/**
 * DT-8 — um download que falha nunca vira documento, não para a rodada e não impede a próxima
 * tentativa. Os dois primeiros testes usam o fake (que deixa o corpo do erro no destino antes de
 * lançar: o pior caso); o último liga o {@see GoogleDriveClient} real ao dublê de rede.
 */
#[CoversClass(ReconciliadorDePasta::class)]
#[CoversClass(ReconciliarCommand::class)]
final class ReconciliadorFalhaDeDownloadTest extends KernelTestCase
{
    use Factories;

    private const ROOT = 'ROOT';
    private const PDF  = "%PDF-1.4\nconteudo-conhecido";

    /** @var list<string> */
    private array $arquivosGravados = [];

    protected function tearDown(): void
    {
        foreach ($this->arquivosGravados as $arquivo) {
            if (is_file($arquivo)) {
                unlink($arquivo);
            }
        }
        parent::tearDown();
    }

    private function tester(FakeGoogleDriveClient $fake): CommandTester
    {
        self::getContainer()->set(
            GoogleDriveClientFactoryInterface::class,
            new FakeGoogleDriveClientFactory($fake, self::ROOT),
        );

        return new CommandTester((new Application(self::$kernel))->find('app:sync:reconciliar'));
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array<string, string> drive_file_id => conteúdo gravado no storage */
    private function documentosDaPasta(int $pastaId): array
    {
        $storage    = self::getContainer()->get(ArquivoStorageInterface::class);
        $uploadsDir = (string) self::getContainer()->getParameter('uploads_dir');
        $rows       = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT drive_file_id, caminho_arquivo FROM pasta_documento WHERE pasta_id = :p ORDER BY id',
            ['p' => $pastaId],
        );

        $documentos = [];
        foreach ($rows as $row) {
            $caminho                  = $storage->caminho($uploadsDir, (string) $row['caminho_arquivo']);
            $this->arquivosGravados[] = $caminho;
            $documentos[(string) $row['drive_file_id']] = (string) file_get_contents($caminho);
        }

        return $documentos;
    }

    #[TestDox('download que falha não vira documento, o temporário some e a rodada segue para o próximo arquivo')]
    public function testDownloadQueFalhaNaoViraDocumentoEARodadaSegue(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '801', 'nomeCliente' => 'DT8', 'driveFolderId' => 'CASE-DT8']);

        $fake = new FakeGoogleDriveClient();
        $fake->seedArquivo('F-FALHA', 'contrato.pdf', 'CASE-DT8');
        $fake->seedArquivo('F-BOM', 'procuracao.pdf', 'CASE-DT8');
        $fake->falharDownload('F-FALHA');

        $tester = $this->tester($fake);
        $tester->execute(['--modo' => 'importar', '--tenant-id' => (string) $tenant->getId(), '--usuario-id' => (string) $user->getId()]);
        $this->em()->clear();
        $documentos = $this->documentosDaPasta($pasta->getId());

        $tester->assertCommandIsSuccessful();
        self::assertSame(['F-BOM' => 'conteudo-fake-F-BOM'], $documentos);
        $saida = $tester->getDisplay();
        self::assertStringContainsString('[erro] drive_file_id=F-FALHA', $saida);
        self::assertStringContainsString('respondeu 403', $saida);
        self::assertCount(2, $fake->destinosDeDownload, 'a rodada parou no arquivo que falhou');
        foreach ($fake->destinosDeDownload as $destino) {
            self::assertFileDoesNotExist($destino, 'o temporário do download sobrou');
        }
    }

    #[TestDox('a rodada seguinte tenta de novo o download que falhou e só então cria o documento')]
    public function testRodadaSeguinteTentaDeNovoODownloadQueFalhou(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '802', 'nomeCliente' => 'DT8', 'driveFolderId' => 'CASE-DT8-B']);

        $fake = new FakeGoogleDriveClient();
        $fake->seedArquivo('F-FALHA', 'contrato.pdf', 'CASE-DT8-B');
        $fake->falharDownload('F-FALHA');
        $opcoes = ['--modo' => 'importar', '--tenant-id' => (string) $tenant->getId(), '--usuario-id' => (string) $user->getId()];

        $tester = $this->tester($fake);
        $tester->execute($opcoes);
        $this->em()->clear();
        self::assertSame([], $this->documentosDaPasta($pasta->getId()));

        $tester->execute($opcoes);
        $this->em()->clear();
        $documentos = $this->documentosDaPasta($pasta->getId());

        $tester->assertCommandIsSuccessful();
        self::assertSame(['F-FALHA' => 'conteudo-fake-F-FALHA'], $documentos);
        self::assertCount(2, $fake->destinosDeDownload, 'a segunda rodada não tentou o download de novo');
    }

    #[TestDox('ponta a ponta: com o client real, o 403 do Drive não vira documento e a próxima rodada importa')]
    public function testCorpoDeErroDoDriveNaoViraDocumento(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '803', 'nomeCliente' => 'DT8', 'driveFolderId' => 'CASE-E2E']);

        $erroDoDrive = DriveHttpFalso::erroDoGoogle(403, 'downloadQuotaExceeded', 'The download quota for this file has been exceeded.');
        $http        = (new DriveHttpFalso())
            ->tokenAceito()
            ->rota('GET', DriveHttpFalso::HOST_API . '/drive/v3/files', static function (RequestInterface $r): Response {
                parse_str($r->getUri()->getQuery(), $query);
                $q        = (string) ($query['q'] ?? '');
                $arquivos = [];
                if (str_contains($q, "'CASE-E2E' in parents") && str_contains($q, "mimeType != 'application/vnd.google-apps.folder'")) {
                    $arquivos = [
                        ['id' => 'ARQ-ERRO', 'name' => 'contrato.pdf', 'size' => (string) strlen(self::PDF), 'mimeType' => 'application/pdf'],
                        ['id' => 'ARQ-BOM', 'name' => 'procuracao.pdf', 'size' => (string) strlen(self::PDF), 'mimeType' => 'application/pdf'],
                    ];
                }

                return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['files' => $arquivos]));
            })
            ->download('ARQ-ERRO', DriveHttpFalso::responder(403, $erroDoDrive, ['Content-Type' => 'application/json']))
            ->download('ARQ-BOM', DriveHttpFalso::responder(200, self::PDF, ['Content-Type' => 'application/pdf']));
        // A configuração padrão do Google (http_errors desligado), como em produção.
        $drive = GoogleDriveClient::comClienteHttp(
            googleDriveCredentials: null,
            googleDriveOauthClientId: 'id-do-app',
            googleDriveOauthClientSecret: 'segredo-do-app',
            googleDriveOauthRefreshToken: 'refresh-do-escritorio',
            clienteHttp: $http->clienteHttp(),
        );
        $reconciliador = self::getContainer()->get(ReconciliadorDePasta::class);

        $r = new ResultadoReconciliacaoPasta();
        $reconciliador->reconciliarArquivosDaPasta($pasta->getId(), $drive, false, $r, ModoSincronizacao::Importar);
        $this->em()->clear();

        self::assertSame(['ARQ-BOM' => self::PDF], $this->documentosDaPasta($pasta->getId()), 'o corpo do 403 virou documento');
        self::assertSame(1, $r->erros);
        self::assertSame(1, $r->arquivosBaixados);
        self::assertFalse($r->fatal);
        $log = implode("\n", $r->mensagens);
        self::assertStringContainsString('[erro] drive_file_id=ARQ-ERRO', $log);
        self::assertStringContainsString('respondeu 403', $log);
        self::assertStringContainsString('downloadQuotaExceeded', $log);
        self::assertStringNotContainsString('refresh-do-escritorio', $log);
        $destinosDoErro = $http->destinos['GET ' . DriveHttpFalso::HOST_API . '/drive/v3/files/ARQ-ERRO'] ?? [];
        self::assertCount(1, $destinosDoErro);
        // Client e reconciliador apagam o temporário (defesa dupla); os unitários provam o client sozinho.
        self::assertFileDoesNotExist($destinosDoErro[0], 'o temporário do 403 sobrou');

        // O Drive volta a entregar o arquivo: a rodada seguinte tenta de novo e importa.
        $http->download('ARQ-ERRO', DriveHttpFalso::responder(200, self::PDF, ['Content-Type' => 'application/pdf']));
        $r2 = new ResultadoReconciliacaoPasta();
        $reconciliador->reconciliarArquivosDaPasta($pasta->getId(), $drive, false, $r2, ModoSincronizacao::Importar);
        $this->em()->clear();

        self::assertSame(['ARQ-BOM' => self::PDF, 'ARQ-ERRO' => self::PDF], $this->documentosDaPasta($pasta->getId()));
        self::assertSame(0, $r2->erros);
        self::assertSame(1, $r2->arquivosBaixados, 'o arquivo já importado foi baixado de novo');
    }
}
