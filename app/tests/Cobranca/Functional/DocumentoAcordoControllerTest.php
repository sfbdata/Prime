<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\AcordoController;
use App\Cobranca\Entity\AcordoDocumento;
use App\Cobranca\Enum\StatusAcordo;
use App\Tests\Factory\Cobranca\AcordoDocumentoFactory;
use App\Tests\Factory\Cobranca\AcordoFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Documentos do Acordo (Ajuste #4): aba "Documentos" na tela do acordo, lista simples e
 * cronológica, upload via form multipart puro (não é o file-manager JSON do Caso) + PRG. Prova:
 * upload aparece na lista do `show`, download serve o arquivo, excluir some da lista, gate de
 * capacidade, CSRF e anti-IDOR (404 cross-tenant) em todos os endpoints de mutação/leitura por id.
 */
#[CoversClass(AcordoController::class)]
final class DocumentoAcordoControllerTest extends CobrancaWebTestCase
{
    #[TestDox('Upload de documento do acordo: aparece na aba Documentos do show')]
    public function testUploadApareceNaListaDoShow(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo])->_real();
        $acordoId = (int) $acordo->getId();

        $crawler = $client->request('GET', '/cobrancas/acordos/' . $acordoId);
        $token = (string) $crawler->filter('#modalAdicionarDocumentoAcordo input[name="_token"]')->attr('value');

        $client->request(
            'POST',
            '/cobrancas/acordos/' . $acordoId . '/documentos',
            ['_token' => $token, 'categoria' => 'termo_de_acordo', 'observacao' => 'Termo assinado pelas partes'],
            ['arquivo' => $this->arquivoTexto()],
        );

        self::assertResponseRedirects('/cobrancas/acordos/' . $acordoId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $documentos = $em->getRepository(AcordoDocumento::class)->findBy(['tenant' => $tenant]);
        self::assertCount(1, $documentos);
        self::assertSame($acordoId, $documentos[0]->getAcordo()?->getId());

        $crawlerShow = $client->request('GET', '/cobrancas/acordos/' . $acordoId);
        self::assertResponseIsSuccessful();
        $texto = $crawlerShow->filter('.cobrancas-page')->text();
        self::assertStringContainsString('comprovante.txt', $texto);
        self::assertStringContainsString('Termo de acordo', $texto);
        self::assertStringContainsString('Termo assinado pelas partes', $texto);
    }

    #[TestDox('Download do documento do acordo: 200')]
    public function testDownloadServeArquivo(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso])->_real();
        $acordoId = (int) $acordo->getId();

        $crawler = $client->request('GET', '/cobrancas/acordos/' . $acordoId);
        $token = (string) $crawler->filter('#modalAdicionarDocumentoAcordo input[name="_token"]')->attr('value');
        $client->request('POST', '/cobrancas/acordos/' . $acordoId . '/documentos', ['_token' => $token], ['arquivo' => $this->arquivoTexto()]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $doc = $em->getRepository(AcordoDocumento::class)->findOneBy(['tenant' => $tenant]);
        self::assertNotNull($doc);

        $client->request('GET', '/cobrancas/acordos/documentos/' . $doc->getId() . '/download');

        self::assertResponseIsSuccessful();
    }

    #[TestDox('Excluir documento do acordo: some da lista (PRG)')]
    public function testExcluirRemoveDaLista(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso])->_real();
        $acordoId = (int) $acordo->getId();
        $doc = AcordoDocumentoFactory::createOne(['tenant' => $tenant, 'acordo' => $acordo, 'nomeOriginal' => 'termo-excluir.pdf']);
        $docId = (int) $doc->getId();

        $crawler = $client->request('GET', '/cobrancas/acordos/' . $acordoId);
        self::assertStringContainsString('termo-excluir.pdf', $crawler->filter('.cobrancas-page')->text());
        $token = (string) $crawler->filter('form[action$="/documentos/' . $docId . '/excluir"] input[name="_token"]')->attr('value');

        $client->request('POST', '/cobrancas/acordos/documentos/' . $docId . '/excluir', ['_token' => $token]);

        self::assertResponseRedirects('/cobrancas/acordos/' . $acordoId);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertNull($em->getRepository(AcordoDocumento::class)->find($docId));

        $crawlerFinal = $client->request('GET', '/cobrancas/acordos/' . $acordoId);
        self::assertStringNotContainsString('termo-excluir.pdf', $crawlerFinal->filter('.cobrancas-page')->text());
    }

    #[TestDox('Upload sem a capacidade de gerenciar: negado, nada persiste')]
    public function testUploadSemCapacidadeNegado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso])->_real();

        $client->request(
            'POST',
            '/cobrancas/acordos/' . $acordo->getId() . '/documentos',
            ['_token' => 'irrelevante'],
            ['arquivo' => $this->arquivoTexto()],
        );

        self::assertResponseRedirects();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(0, $em->getRepository(AcordoDocumento::class)->findBy(['tenant' => $tenant]));
    }

    #[TestDox('Upload com CSRF inválido: não persiste')]
    public function testUploadCsrfInvalido(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso])->_real();
        $acordoId = (int) $acordo->getId();

        $client->request(
            'POST',
            '/cobrancas/acordos/' . $acordoId . '/documentos',
            ['_token' => 'token-falso'],
            ['arquivo' => $this->arquivoTexto()],
        );

        self::assertResponseRedirects('/cobrancas/acordos/' . $acordoId);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(0, $em->getRepository(AcordoDocumento::class)->findBy(['tenant' => $tenant]));
    }

    #[TestDox('IDOR: upload em acordo de outro tenant retorna 404')]
    public function testUploadCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoAlheio] = $this->semearGrafo($this->tenantAvulso());
        $acordoAlheio = AcordoFactory::createOne(['tenant' => $casoAlheio->getTenant(), 'caso' => $casoAlheio])->_real();

        $client->request(
            'POST',
            '/cobrancas/acordos/' . $acordoAlheio->getId() . '/documentos',
            ['_token' => 'irrelevante'],
            ['arquivo' => $this->arquivoTexto()],
        );

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('IDOR: download de documento de acordo de outro tenant retorna 404')]
    public function testDownloadCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        $outroTenant = $this->tenantAvulso();
        [, $casoAlheio] = $this->semearGrafo($outroTenant);
        $acordoAlheio = AcordoFactory::createOne(['tenant' => $outroTenant, 'caso' => $casoAlheio])->_real();
        $docAlheio = AcordoDocumentoFactory::createOne(['tenant' => $outroTenant, 'acordo' => $acordoAlheio]);

        $client->request('GET', '/cobrancas/acordos/documentos/' . $docAlheio->getId() . '/download');

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('IDOR: excluir documento de acordo de outro tenant retorna 404')]
    public function testExcluirCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        $outroTenant = $this->tenantAvulso();
        [, $casoAlheio] = $this->semearGrafo($outroTenant);
        $acordoAlheio = AcordoFactory::createOne(['tenant' => $outroTenant, 'caso' => $casoAlheio])->_real();
        $docAlheio = AcordoDocumentoFactory::createOne(['tenant' => $outroTenant, 'acordo' => $acordoAlheio]);

        $client->request('POST', '/cobrancas/acordos/documentos/' . $docAlheio->getId() . '/excluir', ['_token' => 'irrelevante']);

        self::assertResponseStatusCodeSame(404);
    }

    private function arquivoTexto(): UploadedFile
    {
        $caminho = tempnam(sys_get_temp_dir(), 'acor') . '.txt';
        file_put_contents($caminho, "termo de acordo\n");

        return new UploadedFile($caminho, 'comprovante.txt', 'text/plain', null, true);
    }

    protected function tearDown(): void
    {
        $dir = __DIR__ . '/../../../var/uploads-test/cobrancas';
        if (is_dir($dir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
        }

        parent::tearDown();
    }
}
