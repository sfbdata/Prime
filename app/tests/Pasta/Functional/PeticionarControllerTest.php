<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Pasta\Pasta;
use App\Entity\Pasta\PastaDocumento;
use App\Pasta\Controller\PeticionarController;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(PeticionarController::class)]
final class PeticionarControllerTest extends WebTestCase
{
    private function criarUsuarioAdmin(): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new \App\Entity\Tenant\Tenant();
        $tenant->setName('Tenant Peticionar ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_petic_' . uniqid() . '@test.com');
        $user->setFullName('Admin Peticionar');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setTenant($tenant);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function criarPasta(User $user): Pasta
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup('NUP-PETIC-' . uniqid());
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }

    private function criarDocumento(Pasta $pasta): PastaDocumento
    {
        $em  = static::getContainer()->get(EntityManagerInterface::class);
        $doc = new PastaDocumento();
        $doc->setPasta($pasta);
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
        $client = static::createClient();
        $user   = $this->criarUsuarioAdmin();
        $pasta  = $this->criarPasta($user);
        $this->criarDocumento($pasta);
        $client->loginUser($user);

        $client->request('GET', "/pasta/{$pasta->getId()}/peticionar");

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($pasta->getNup(), (string) $client->getResponse()->getContent());
    }

    #[TestDox('GET peticionar de pasta inexistente retorna 404')]
    public function testGetPastaInexistenteRetorna404(): void
    {
        $client = static::createClient();
        $user   = $this->criarUsuarioAdmin();
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
        $client = static::createClient();
        $user   = $this->criarUsuarioAdmin();
        $pasta  = $this->criarPasta($user);
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
        $client = static::createClient();
        $user   = $this->criarUsuarioAdmin();
        $pasta  = $this->criarPasta($user);
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
        $client = static::createClient();
        $user   = $this->criarUsuarioAdmin();
        $pasta  = $this->criarPasta($user);
        $this->instalarCsrfStorage();
        $client->loginUser($user);

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
        self::assertSame('peticao.pdf', $data['documento']['titulo']);
        self::assertSame('PECA', $data['documento']['categoria']);

        if (isset($tmpFile) && file_exists($tmpFile)) {
            @unlink($tmpFile);
        }
    }
}
