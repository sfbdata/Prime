<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Entity\Tenant\Tenant;
use App\Pasta\Controller\PeticionarController;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(PeticionarController::class)]
final class PeticionarControllerTest extends JusPrimeWebTestCase
{
    /** @var string[] */
    private array $arquivosParaLimpar = [];

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->arquivosParaLimpar as $arquivo) {
            if (file_exists($arquivo)) {
                @unlink($arquivo);
            }
        }
    }

    private function criarUsuarioAdmin(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Peticionar ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_petic_' . uniqid() . '@test.com');
        $user->setFullName('Admin Peticionar');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $userTenant = new UserTenant($user, $tenant);
        $em->persist($userTenant);
        $em->flush();

        return [$user, $tenant];
    }

    private function criarPasta(Tenant $tenant): Pasta
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup('NUP-PETIC-' . uniqid());
        $pasta->setTenant($tenant);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }

    private function criarDocumentoHtml(Pasta $pasta, Tenant $tenant): PastaDocumento
    {
        $container  = static::getContainer();
        $em         = $container->get(EntityManagerInterface::class);
        $uploadsDir = (string) $container->getParameter('uploads_dir');

        $nomeArquivo = 'test_html_' . uniqid() . '.html';
        $caminho     = $uploadsDir . '/' . $nomeArquivo;
        $conteudo    = '<p>Conteúdo de teste com acentuação: ç, ã, é.</p>';
        file_put_contents($caminho, $conteudo);
        $this->arquivosParaLimpar[] = $caminho;

        $doc = new PastaDocumento();
        $doc->setPasta($pasta);
        $doc->setTenant($tenant);
        $doc->setTitulo('Peça de Texto Teste');
        $doc->setCategoria(PastaDocumento::CATEGORIA_PECA);
        $doc->setCaminhoArquivo($nomeArquivo);
        $doc->setNomeOriginal('Peça de Texto Teste.html');
        $doc->setMimeType('text/html');
        $doc->setTamanhoBytes(strlen($conteudo));
        $em->persist($doc);
        $em->flush();

        return $doc;
    }

    private function criarDocumento(Pasta $pasta, Tenant $tenant): PastaDocumento
    {
        $em  = static::getContainer()->get(EntityManagerInterface::class);
        $doc = new PastaDocumento();
        $doc->setPasta($pasta);
        $doc->setTenant($tenant);
        $doc->setTitulo('Petição Inicial.pdf');
        $doc->setCategoria(PastaDocumento::CATEGORIA_PECA);
        $doc->setCaminhoArquivo('fake_file.pdf');
        $doc->setNomeOriginal('Petição Inicial.pdf');
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(1024);
        $em->persist($doc);
        $em->flush();

        return $doc;
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

    // ── GET ────────────────────────────────────────────────────────────────────

    #[TestDox('GET peticionar sem autenticação redireciona para login')]
    public function testGetSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('GET', '/pasta/1/peticionar');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('GET peticionar com usuário autenticado retorna 200')]
    public function testGetRetorna200(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->criarDocumento($pasta, $tenant);
        $client->loginUser($user);

        $client->request('GET', "/pasta/{$pasta->getId()}/peticionar");

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($pasta->getNup(), (string) $client->getResponse()->getContent());
    }

    #[TestDox('GET peticionar de pasta inexistente retorna 404')]
    public function testGetPastaInexistenteRetorna404(): void
    {
        $client    = static::createClient();
        [$user]    = $this->criarUsuarioAdmin();
        $client->loginUser($user);

        $client->request('GET', '/pasta/99999/peticionar');

        self::assertResponseStatusCodeSame(404);
    }

    // ── POST upload ────────────────────────────────────────────────────────────

    #[TestDox('POST upload sem autenticação redireciona para login')]
    public function testUploadSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('POST', '/pasta/1/peticionar/upload');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('POST upload com CSRF inválido retorna 403')]
    public function testUploadCsrfInvalidoRetorna403(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $client->loginUser($user);

        $client->request('POST', "/pasta/{$pasta->getId()}/peticionar/upload", [
            '_token' => 'token_invalido',
        ]);

        self::assertResponseStatusCodeSame(403);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($data['success']);
    }

    #[TestDox('POST upload sem arquivo retorna 400')]
    public function testUploadSemArquivoRetorna400(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->instalarCsrfStorage();
        $client->loginUser($user);

        $client->request('POST', "/pasta/{$pasta->getId()}/peticionar/upload", [
            '_token' => $this->csrf('peticionar_upload_' . $pasta->getId()),
        ]);

        self::assertResponseStatusCodeSame(400);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($data['success']);
    }

    #[TestDox('POST upload com arquivo válido retorna 200 com dados do documento')]
    public function testUploadArquivoValidoRetorna200(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $tmpFile = tempnam(sys_get_temp_dir(), 'test_pdf_');
        file_put_contents($tmpFile, '%PDF-1.4 dummy');
        $uploadedFile = new UploadedFile($tmpFile, 'peticao.pdf', 'application/pdf', null, true);

        $client->request(
            'POST',
            "/pasta/{$pasta->getId()}/peticionar/upload",
            ['_token' => $this->csrf('peticionar_upload_' . $pasta->getId()), 'categoria' => 'PECA'],
            ['arquivo' => $uploadedFile],
        );

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($data['success']);
        self::assertArrayHasKey('documento', $data);
        self::assertSame('PETICAO.PDF', $data['documento']['titulo']);
        self::assertSame('PECA', $data['documento']['categoria']);

        if (isset($tmpFile) && file_exists($tmpFile)) {
            @unlink($tmpFile);
        }
    }

    // ── POST texto (criar) ─────────────────────────────────────────────────────

    #[TestDox('POST texto sem autenticação redireciona para login')]
    public function testCriarTextoSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('POST', '/pasta/1/peticionar/texto');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('POST texto com dados válidos retorna 200 com documento criado')]
    public function testCriarTextoComDadosValidosRetorna200(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/peticionar/texto", [
            '_token'    => $this->csrf('peticionar_texto_' . $pasta->getId()),
            'titulo'    => 'Petição Inicial',
            'categoria' => 'PECA',
            'conteudo'  => '<p>Conteúdo da peça</p>',
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($data['success']);
        self::assertArrayHasKey('documento', $data);
        self::assertSame('PETIÇÃO INICIAL', $data['documento']['titulo']);
        self::assertSame('text/html', $data['documento']['mimeType']);
    }

    #[TestDox('POST texto com CSRF inválido retorna 403')]
    public function testCriarTextoComCsrfInvalidoRetorna403(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $client->loginUser($user);

        $client->request('POST', "/pasta/{$pasta->getId()}/peticionar/texto", [
            '_token'   => 'token_invalido',
            'titulo'   => 'Petição',
            'conteudo' => '<p>HTML</p>',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('POST texto com título vazio retorna 400')]
    public function testCriarTextoComTituloVazioRetorna400(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/peticionar/texto", [
            '_token'   => $this->csrf('peticionar_texto_' . $pasta->getId()),
            'titulo'   => '',
            'conteudo' => '<p>HTML</p>',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($data['success']);
    }

    // ── POST imagem (upload para editor) ───────────────────────────────────────

    #[TestDox('POST imagem sem autenticação redireciona para login')]
    public function testUploadImagemEditorSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('POST', '/pasta/1/peticionar/imagem');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('POST imagem com JPEG válido retorna 200 com URL')]
    public function testUploadImagemEditorComJpegValidoRetorna200(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->instalarCsrfStorage();
        $client->loginUser($user);

        // Criar arquivo com magic bytes de JPEG para que getMimeType() retorne image/jpeg
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_jpg_');
        file_put_contents($tmpFile, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00");
        $uploadedFile = new UploadedFile($tmpFile, 'foto.jpg', 'image/jpeg', null, true);

        $client->request(
            'POST',
            "/pasta/{$pasta->getId()}/peticionar/imagem",
            ['_token' => $this->csrf('upload_imagem_editor_' . $pasta->getId())],
            ['imagem' => $uploadedFile],
        );

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('url', $data);
        self::assertStringStartsWith('/uploads/', $data['url']);

        @unlink($tmpFile);
    }

    #[TestDox('POST imagem com MIME inválido retorna 422')]
    public function testUploadImagemEditorComMimeInvalidoRetorna422(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->instalarCsrfStorage();
        $client->loginUser($user);

        $tmpFile = tempnam(sys_get_temp_dir(), 'test_txt_');
        file_put_contents($tmpFile, 'Texto simples — não é imagem');
        $uploadedFile = new UploadedFile($tmpFile, 'arquivo.txt', 'text/plain', null, true);

        $client->request(
            'POST',
            "/pasta/{$pasta->getId()}/peticionar/imagem",
            ['_token' => $this->csrf('upload_imagem_editor_' . $pasta->getId())],
            ['imagem' => $uploadedFile],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('erro', $data);

        @unlink($tmpFile);
    }

    // ── PUT documento/texto (editar) ───────────────────────────────────────────

    #[TestDox('PUT texto sem autenticação redireciona para login')]
    public function testEditarTextoSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('PUT', '/pasta/documento/1/texto');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('PUT texto com dados válidos retorna 200')]
    public function testEditarTextoComDadosValidosRetorna200(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $doc             = $this->criarDocumentoHtml($pasta, $tenant);
        $this->instalarCsrfStorage();
        $client->loginUser($user);

        $client->request(
            'PUT',
            "/pasta/documento/{$doc->getId()}/texto",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode([
                '_token'  => $this->csrf('peticionar_editar_' . $doc->getId()),
                'conteudo' => '<p>Conteúdo editado com acentuação ã</p>',
                'titulo'   => 'Novo Título',
            ]),
        );

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($data['success']);
    }

    #[TestDox('PUT texto com CSRF inválido retorna 403')]
    public function testEditarTextoComCsrfInvalidoRetorna403(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $doc             = $this->criarDocumentoHtml($pasta, $tenant);
        $client->loginUser($user);

        $client->request(
            'PUT',
            "/pasta/documento/{$doc->getId()}/texto",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode([
                '_token'  => 'token_invalido',
                'conteudo' => '<p>HTML</p>',
            ]),
        );

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('PUT texto em documento não HTML retorna 400')]
    public function testEditarTextoEmDocumentoNaoHtmlRetorna400(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $doc             = $this->criarDocumento($pasta, $tenant); // MIME: application/pdf
        $this->instalarCsrfStorage();
        $client->loginUser($user);

        $client->request(
            'PUT',
            "/pasta/documento/{$doc->getId()}/texto",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode([
                '_token'  => $this->csrf('peticionar_editar_' . $doc->getId()),
                'conteudo' => '<p>HTML</p>',
            ]),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($data['success']);
    }

    // ── GET documento/exportar (exportar) ──────────────────────────────────────

    #[TestDox('GET exportar sem autenticação redireciona para login')]
    public function testExportarTextoSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('GET', '/pasta/documento/1/exportar/docx');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('GET exportar DOCX retorna 200 com Content-Type correto')]
    public function testExportarTextoEmDocxRetorna200ComHeaders(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $doc             = $this->criarDocumentoHtml($pasta, $tenant);
        $client->loginUser($user);

        $client->request('GET', "/pasta/documento/{$doc->getId()}/exportar/docx");

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            (string) $client->getResponse()->headers->get('Content-Type'),
        );
        self::assertStringContainsString(
            '.docx',
            (string) $client->getResponse()->headers->get('Content-Disposition'),
        );
    }

    #[TestDox('GET exportar PDF retorna 200 com Content-Type application/pdf')]
    public function testExportarTextoEmPdfRetorna200ComHeaders(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $doc             = $this->criarDocumentoHtml($pasta, $tenant);
        $client->loginUser($user);

        $client->request('GET', "/pasta/documento/{$doc->getId()}/exportar/pdf");

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'application/pdf',
            (string) $client->getResponse()->headers->get('Content-Type'),
        );
        self::assertStringStartsWith('%PDF-', (string) $client->getResponse()->getContent());
    }

    #[TestDox('GET exportar com formato inválido retorna 400')]
    public function testExportarTextoComFormatoInvalidoRetorna400(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $doc             = $this->criarDocumentoHtml($pasta, $tenant);
        $client->loginUser($user);

        $client->request('GET', "/pasta/documento/{$doc->getId()}/exportar/xml");

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    #[TestDox('GET exportar em documento não HTML retorna 400')]
    public function testExportarTextoEmDocumentoNaoHtmlRetorna400(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $doc             = $this->criarDocumento($pasta, $tenant); // MIME: application/pdf
        $client->loginUser($user);

        $client->request('GET', "/pasta/documento/{$doc->getId()}/exportar/docx");

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }
}
