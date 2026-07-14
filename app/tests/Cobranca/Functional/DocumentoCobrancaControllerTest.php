<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\DocumentoCobrancaController;
use App\Cobranca\Entity\CobrancaDocumento;
use App\Cobranca\Entity\CobrancaSecao;
use App\Tests\Factory\Cobranca\CobrancaDocumentoFactory;
use App\Tests\Factory\Cobranca\CobrancaSecaoFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * File-manager de documentos do Caso (Onda 8C) via HTTP. Reusa o contrato JSON do gerenciador das
 * Pastas. Prova: upload cria documento no Caso SEM Pasta (INV-25); CRUD de seção e mover devolvem o
 * contrato esperado pelo JS; reordenar aceita o corpo JSON; excluir documento redireciona; download
 * serve o arquivo; e a segurança (capacidade, IDOR entre tenants, CSRF) barra o que deve barrar.
 *
 * CSRF: os endpoints AJAX usam tokens NOMEADOS (stateful). Segue o padrão do PastaSecaoControllerTest —
 * substitui o storage por um stub determinístico (`TOKEN_<id>`) e, quando o teste faz mais de uma
 * requisição, desativa o reboot do kernel para o stub/sessão persistirem.
 */
#[CoversClass(DocumentoCobrancaController::class)]
final class DocumentoCobrancaControllerTest extends CobrancaWebTestCase
{
    #[TestDox('Upload cria documento no Caso (sem Pasta) e o download serve o arquivo')]
    public function testUploadCriaDocumentoSemPastaEfazDownload(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();
        $this->instalarCsrfStorage();

        $crawler = $client->request('GET', "/cobrancas/objetos/{$caso->getObjeto()->getId()}");
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#fileManager'), 'gestor vê o file-manager de escrita');

        $client->request('POST', "/cobrancas/casos/{$casoId}/documentos", ['_token' => $this->csrf('cobranca_documento_upload_' . $casoId)], ['arquivo' => $this->arquivoTexto()]);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($payload['success'] ?? false);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $doc = $em->getRepository(CobrancaDocumento::class)->findOneBy(['tenant' => $tenant]);
        self::assertNotNull($doc);
        self::assertSame($casoId, $doc->getCaso()?->getId(), 'documento vinculado ao Caso (INV-25: sem Pasta)');
        self::assertNull($doc->getSecao());

        $client->request('GET', "/cobrancas/documentos/{$doc->getId()}/download");
        self::assertResponseIsSuccessful();
    }

    #[TestDox('Criar, renomear e excluir seção seguem o contrato JSON do file-manager')]
    public function testCrudSecaoContratoJson(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();
        $this->instalarCsrfStorage();

        $client->request('POST', "/cobrancas/casos/{$casoId}/secoes", ['_token' => $this->csrf('cobranca_secao_criar_' . $casoId), 'nome' => 'Comprovantes']);
        self::assertResponseStatusCodeSame(201);
        $criada = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('id', $criada);
        self::assertArrayHasKey('csrfRenomear', $criada);
        self::assertArrayHasKey('csrfExcluir', $criada);
        $secaoId = (int) $criada['id'];

        $client->request('POST', "/cobrancas/secoes/{$secaoId}/renomear", ['_token' => $this->csrf('cobranca_secao_renomear_' . $secaoId), 'nome' => 'Recibos']);
        self::assertResponseIsSuccessful();
        $ren = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($ren['ok'] ?? false);
        self::assertSame('RECIBOS', $ren['nome']);

        $client->request('POST', "/cobrancas/secoes/{$secaoId}/excluir", ['_token' => $this->csrf('cobranca_secao_excluir_' . $secaoId)]);
        self::assertResponseIsSuccessful();
        $exc = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($exc['ok'] ?? false);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertNull($em->getRepository(CobrancaSecao::class)->find($secaoId));
    }

    #[TestDox('Mover documento para uma seção do mesmo caso devolve {ok:true}')]
    public function testMoverDocumento(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $secao = CobrancaSecaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso]);
        $doc = CobrancaDocumentoFactory::createOne(['tenant' => $tenant, 'caso' => $caso]);
        $docId = (int) $doc->getId();
        $secaoId = (int) $secao->getId();
        $this->instalarCsrfStorage();

        $client->request('POST', "/cobrancas/documentos/{$docId}/mover", ['_token' => $this->csrf('cobranca_doc_mover_' . $docId), 'secao_id' => (string) $secaoId]);

        self::assertResponseIsSuccessful();
        self::assertTrue((json_decode((string) $client->getResponse()->getContent(), true))['ok'] ?? false);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame($secaoId, $em->getRepository(CobrancaDocumento::class)->find($docId)->getSecao()?->getId());
    }

    #[TestDox('Reordenar documentos aceita o corpo JSON e responde 2xx')]
    public function testReordenarDocumentos(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();

        $a = CobrancaDocumentoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'ordem' => 1]);
        $b = CobrancaDocumentoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'ordem' => 2]);
        $this->instalarCsrfStorage();

        $client->request('POST', "/cobrancas/casos/{$casoId}/documentos/reordenar", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            '_token' => $this->csrf('reordenar_docs_cobranca_' . $casoId),
            'ids' => [(int) $b->getId(), (int) $a->getId()],
        ]));

        self::assertResponseIsSuccessful();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame(1, $em->getRepository(CobrancaDocumento::class)->find($b->getId())->getOrdem());
        self::assertSame(2, $em->getRepository(CobrancaDocumento::class)->find($a->getId())->getOrdem());
    }

    #[TestDox('Reordenar seções aceita o corpo JSON e responde 2xx')]
    public function testReordenarSecoes(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();

        $a = CobrancaSecaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'ordem' => 1]);
        $b = CobrancaSecaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'ordem' => 2]);
        $this->instalarCsrfStorage();

        $client->request('POST', "/cobrancas/casos/{$casoId}/secoes/reordenar", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            '_token' => $this->csrf('reordenar_secoes_cobranca_' . $casoId),
            'ids' => [(int) $b->getId(), (int) $a->getId()],
        ]));

        self::assertResponseIsSuccessful();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame(1, $em->getRepository(CobrancaSecao::class)->find($b->getId())->getOrdem());
        self::assertSame(2, $em->getRepository(CobrancaSecao::class)->find($a->getId())->getOrdem());
    }

    #[TestDox('Download de documento de outro tenant retorna 404 (anti-IDOR)')]
    public function testIdorDownloadDeOutroTenant(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoOutro] = $this->semearGrafo($this->tenantAvulso());
        $docOutro = CobrancaDocumentoFactory::createOne(['tenant' => $casoOutro->getTenant(), 'caso' => $casoOutro]);

        $client->request('GET', "/cobrancas/documentos/{$docOutro->getId()}/download");

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Renomear seção de outro tenant retorna 404 (anti-IDOR)')]
    public function testIdorRenomearSecaoDeOutroTenant(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoOutro] = $this->semearGrafo($this->tenantAvulso());
        $secaoOutra = CobrancaSecaoFactory::createOne(['tenant' => $casoOutro->getTenant(), 'caso' => $casoOutro]);

        $client->request('POST', "/cobrancas/secoes/{$secaoOutra->getId()}/renomear", ['_token' => 'x', 'nome' => 'X']);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Upload com secao_id de outro tenant retorna 404 (anti-IDOR)')]
    public function testUploadComSecaoDeOutroTenant(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();
        [, $casoOutro] = $this->semearGrafo($this->tenantAvulso());
        $secaoOutra = CobrancaSecaoFactory::createOne(['tenant' => $casoOutro->getTenant(), 'caso' => $casoOutro]);
        $this->instalarCsrfStorage();

        $client->request(
            'POST',
            "/cobrancas/casos/{$casoId}/documentos",
            ['_token' => $this->csrf('cobranca_documento_upload_' . $casoId), 'secao_id' => (string) $secaoOutra->getId()],
            ['arquivo' => $this->arquivoTexto()],
        );

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, static::getContainer()->get(EntityManagerInterface::class)->getRepository(CobrancaDocumento::class)->count(['tenant' => $tenant]));
    }

    #[TestDox('Excluir documento (form) redireciona para o objeto e remove a linha')]
    public function testExcluirDocumentoRedireciona(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();

        $doc = CobrancaDocumentoFactory::createOne(['tenant' => $tenant, 'caso' => $caso]);
        $docId = (int) $doc->getId();
        $this->instalarCsrfStorage();

        $client->request('POST', "/cobrancas/documentos/{$docId}/excluir", ['_token' => $this->csrf('delete_documento_cobranca_' . $docId)]);

        self::assertResponseRedirects("/cobrancas/objetos/{$caso->getObjeto()->getId()}");
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertNull($em->getRepository(CobrancaDocumento::class)->find($docId));
    }

    #[TestDox('Sem a capacidade gerenciar, o upload retorna 403 JSON')]
    public function testSemCapacidadeRetorna403(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);

        $client->request('POST', "/cobrancas/casos/{$caso->getId()}/documentos", ['_token' => 'x'], ['arquivo' => $this->arquivoTexto()]);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Caso de outro tenant retorna 404 ao criar seção (anti-IDOR)')]
    public function testIdorCasoDeOutroTenant(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoOutro] = $this->semearGrafo($this->tenantAvulso());

        $client->request('POST', "/cobrancas/casos/{$casoOutro->getId()}/secoes", ['_token' => 'x', 'nome' => 'X']);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Documento de outro tenant retorna 404 ao mover (anti-IDOR)')]
    public function testIdorDocumentoDeOutroTenant(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoOutro] = $this->semearGrafo($this->tenantAvulso());
        $docOutro = CobrancaDocumentoFactory::createOne(['tenant' => $casoOutro->getTenant(), 'caso' => $casoOutro]);

        $client->request('POST', "/cobrancas/documentos/{$docOutro->getId()}/mover", ['_token' => 'x']);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('CSRF inválido barra a criação de seção (400)')]
    public function testCsrfInvalidoBarra(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $this->instalarCsrfStorage();

        $client->request('POST', "/cobrancas/casos/{$caso->getId()}/secoes", ['_token' => 'invalido', 'nome' => 'X']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(0, static::getContainer()->get(EntityManagerInterface::class)->getRepository(CobrancaSecao::class)->count(['tenant' => $tenant]));
    }

    #[TestDox('Leitor sem gerenciar vê a lista read-only, sem o file-manager de escrita')]
    public function testLeitorVeListaReadOnly(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorComCapacidades($client, []);
        [, $caso] = $this->semearGrafo($tenant);
        CobrancaDocumentoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'nomeOriginal' => 'contrato.pdf']);

        $crawler = $client->request('GET', "/cobrancas/objetos/{$caso->getObjeto()->getId()}");

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#fileManager'), 'leitor NÃO vê o file-manager de escrita');
        self::assertStringContainsString('contrato.pdf', (string) $client->getResponse()->getContent());
    }

    private function arquivoTexto(): UploadedFile
    {
        $caminho = tempnam(sys_get_temp_dir(), 'cob') . '.txt';
        file_put_contents($caminho, "comprovante de pagamento\n");

        return new UploadedFile($caminho, 'comprovante.txt', 'text/plain', null, true);
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    private function csrf(string $tokenId): string
    {
        return 'TOKEN_' . $tokenId;
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
