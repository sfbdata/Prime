<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\CarteiraController;
use App\Cobranca\Entity\CarteiraDocumento;
use App\Tests\Factory\Cobranca\CarteiraDocumentoFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Documentos da Carteira (Ajuste #5): lista simples e cronológica logo abaixo da configuração,
 * upload via form multipart puro (não é o file-manager JSON do Caso) + PRG. Prova: upload aparece
 * na lista do `show`, download serve o arquivo, excluir some da lista, gate de capacidade,
 * CSRF e anti-IDOR (404 cross-tenant) em todos os endpoints de mutação/leitura por id.
 */
#[CoversClass(CarteiraController::class)]
final class DocumentoCarteiraControllerTest extends CobrancaWebTestCase
{
    #[TestDox('Upload de documento da carteira: aparece na lista do show')]
    public function testUploadApareceNaListaDoShow(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira] = $this->semearGrafo($tenant);
        $carteiraId = (int) $carteira->getId();

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteiraId);
        $token = (string) $crawler->filter('#modalAdicionarDocumentoCarteira input[name="_token"]')->attr('value');

        $client->request(
            'POST',
            '/cobrancas/carteiras/' . $carteiraId . '/documentos',
            ['_token' => $token, 'categoria' => 'ata_de_reuniao', 'observacao' => 'Ata da assembleia anual'],
            ['arquivo' => $this->arquivoTexto()],
        );

        self::assertResponseRedirects('/cobrancas/carteiras/' . $carteiraId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $documentos = $em->getRepository(CarteiraDocumento::class)->findBy(['tenant' => $tenant]);
        self::assertCount(1, $documentos);
        self::assertSame($carteiraId, $documentos[0]->getCarteira()?->getId());

        $crawlerShow = $client->request('GET', '/cobrancas/carteiras/' . $carteiraId);
        self::assertResponseIsSuccessful();
        $texto = $crawlerShow->filter('.cobrancas-page')->text();
        self::assertStringContainsString('comprovante.txt', $texto);
        self::assertStringContainsString('Ata de reunião', $texto);
        self::assertStringContainsString('Ata da assembleia anual', $texto);
    }

    #[TestDox('Download do documento da carteira: 200')]
    public function testDownloadServeArquivo(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira] = $this->semearGrafo($tenant);
        $carteiraId = (int) $carteira->getId();

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteiraId);
        $token = (string) $crawler->filter('#modalAdicionarDocumentoCarteira input[name="_token"]')->attr('value');
        $client->request('POST', '/cobrancas/carteiras/' . $carteiraId . '/documentos', ['_token' => $token], ['arquivo' => $this->arquivoTexto()]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $doc = $em->getRepository(CarteiraDocumento::class)->findOneBy(['tenant' => $tenant]);
        self::assertNotNull($doc);

        $client->request('GET', '/cobrancas/carteiras/documentos/' . $doc->getId() . '/download');

        self::assertResponseIsSuccessful();
    }

    #[TestDox('Excluir documento da carteira: some da lista (PRG)')]
    public function testExcluirRemoveDaLista(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira] = $this->semearGrafo($tenant);
        $carteiraId = (int) $carteira->getId();
        $doc = CarteiraDocumentoFactory::createOne(['tenant' => $tenant, 'carteira' => $carteira, 'nomeOriginal' => 'ata-excluir.pdf']);
        $docId = (int) $doc->getId();

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteiraId);
        self::assertStringContainsString('ata-excluir.pdf', $crawler->filter('.cobrancas-page')->text());
        $token = (string) $crawler->filter('form[action$="/documentos/' . $docId . '/excluir"] input[name="_token"]')->attr('value');

        $client->request('POST', '/cobrancas/carteiras/documentos/' . $docId . '/excluir', ['_token' => $token]);

        self::assertResponseRedirects('/cobrancas/carteiras/' . $carteiraId);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertNull($em->getRepository(CarteiraDocumento::class)->find($docId));

        $crawlerFinal = $client->request('GET', '/cobrancas/carteiras/' . $carteiraId);
        self::assertStringNotContainsString('ata-excluir.pdf', $crawlerFinal->filter('.cobrancas-page')->text());
    }

    #[TestDox('Upload sem a capacidade de gerenciar: negado, nada persiste')]
    public function testUploadSemCapacidadeNegado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [$carteira] = $this->semearGrafo($tenant);

        $client->request(
            'POST',
            '/cobrancas/carteiras/' . $carteira->getId() . '/documentos',
            ['_token' => 'irrelevante'],
            ['arquivo' => $this->arquivoTexto()],
        );

        self::assertResponseRedirects();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(0, $em->getRepository(CarteiraDocumento::class)->findBy(['tenant' => $tenant]));
    }

    #[TestDox('Upload com CSRF inválido: não persiste')]
    public function testUploadCsrfInvalido(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira] = $this->semearGrafo($tenant);
        $carteiraId = (int) $carteira->getId();

        $client->request(
            'POST',
            '/cobrancas/carteiras/' . $carteiraId . '/documentos',
            ['_token' => 'token-falso'],
            ['arquivo' => $this->arquivoTexto()],
        );

        self::assertResponseRedirects('/cobrancas/carteiras/' . $carteiraId);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(0, $em->getRepository(CarteiraDocumento::class)->findBy(['tenant' => $tenant]));
    }

    #[TestDox('IDOR: upload em carteira de outro tenant retorna 404')]
    public function testUploadCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [$carteiraAlheia] = $this->semearGrafo($this->tenantAvulso());

        $client->request(
            'POST',
            '/cobrancas/carteiras/' . $carteiraAlheia->getId() . '/documentos',
            ['_token' => 'irrelevante'],
            ['arquivo' => $this->arquivoTexto()],
        );

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('IDOR: download de documento de carteira de outro tenant retorna 404')]
    public function testDownloadCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        $outroTenant = $this->tenantAvulso();
        [$carteiraAlheia] = $this->semearGrafo($outroTenant);
        $docAlheio = CarteiraDocumentoFactory::createOne(['tenant' => $outroTenant, 'carteira' => $carteiraAlheia]);

        $client->request('GET', '/cobrancas/carteiras/documentos/' . $docAlheio->getId() . '/download');

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('IDOR: excluir documento de carteira de outro tenant retorna 404')]
    public function testExcluirCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        $outroTenant = $this->tenantAvulso();
        [$carteiraAlheia] = $this->semearGrafo($outroTenant);
        $docAlheio = CarteiraDocumentoFactory::createOne(['tenant' => $outroTenant, 'carteira' => $carteiraAlheia]);

        $client->request('POST', '/cobrancas/carteiras/documentos/' . $docAlheio->getId() . '/excluir', ['_token' => 'irrelevante']);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Grampo (#6): a visão da carteira mostra o ícone quando o caso tem documento')]
    public function testGrampoAcendeNaVisaoDaCarteira(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira, $caso] = $this->semearGrafo($tenant);
        \App\Tests\Factory\Cobranca\CobrancaDocumentoFactory::createOne(['tenant' => $tenant, 'caso' => $caso]);

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId());

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('.bi-paperclip[title="O objeto tem documentos"]')->count());
    }

    #[TestDox('Grampo (#6): sem documento, o ícone não aparece na linha do caso')]
    public function testGrampoApagadoSemDocumentos(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira] = $this->semearGrafo($tenant);

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.bi-paperclip[title="O objeto tem documentos"]'));
    }

    private function arquivoTexto(): UploadedFile
    {
        $caminho = tempnam(sys_get_temp_dir(), 'cart') . '.txt';
        file_put_contents($caminho, "ata de reuniao\n");

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
