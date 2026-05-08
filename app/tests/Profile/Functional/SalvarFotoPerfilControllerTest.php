<?php
declare(strict_types=1);
namespace App\Tests\Profile\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Profile\Controller\ProfileController;
use App\Profile\Repository\UserProfileRepository;
use App\Shared\Service\ArquivoStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(ProfileController::class)]
final class SalvarFotoPerfilControllerTest extends WebTestCase
{
    private function criarUsuario(): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Foto ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_foto_' . uniqid() . '@test.com');
        $user->setFullName('Usuário Foto');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setTenant($tenant);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void {}

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    private function gerarCsrf(string $tokenId): string
    {
        return 'TOKEN_' . $tokenId;
    }

    private function storageFake(string $nomeArquivo = 'foto_teste.jpeg'): ArquivoStorageInterface
    {
        return new class($nomeArquivo) implements ArquivoStorageInterface {
            public function __construct(private readonly string $nome) {}

            public function salvar(UploadedFile $arquivo, string $diretorio): string
            {
                return $this->nome;
            }

            public function servir(string $caminhoCompleto, string $nomeOriginal, bool $inline = true): BinaryFileResponse
            {
                throw new \LogicException('não utilizado neste teste');
            }

            public function excluir(string $caminhoCompleto): void {}

            public function existe(string $caminhoCompleto): bool
            {
                return false;
            }

            public function salvarConteudo(string $conteudo, string $diretorio, string $extensao): string
            {
                throw new \LogicException('não utilizado neste teste');
            }

            public function caminho(string $diretorio, string $nomeArquivo): string
            {
                return $diretorio . '/' . $nomeArquivo;
            }
        };
    }

    private function criarJpegTemporario(): string
    {
        $tmpPath = sys_get_temp_dir() . '/test_foto_' . uniqid() . '.jpg';
        // JPEG mínimo válido: SOI + APP0/JFIF + EOI — reconhecido corretamente pelo finfo
        file_put_contents($tmpPath, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9");

        return $tmpPath;
    }

    #[TestDox('POST /perfil/foto com CSRF inválido retorna 403')]
    public function testSalvarFotoRetorna403ComCsrfInvalido(): void
    {
        $client = static::createClient();
        $user   = $this->criarUsuario();
        $client->loginUser($user);

        $client->request('POST', '/perfil/foto', ['_token' => 'token_invalido']);

        self::assertResponseStatusCodeSame(403);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('erro', $data);
    }

    #[TestDox('POST /perfil/foto sem arquivo retorna 400')]
    public function testSalvarFotoRetorna400SemArquivo(): void
    {
        $client = static::createClient();
        $user   = $this->criarUsuario();
        $this->instalarCsrfStorage();
        $client->loginUser($user);

        $client->request('POST', '/perfil/foto', ['_token' => $this->gerarCsrf('profile_foto')]);

        self::assertResponseStatusCodeSame(400);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Nenhuma imagem enviada.', $data['erro']);
    }

    #[TestDox('POST /perfil/foto com MIME inválido retorna 422')]
    public function testSalvarFotoRetorna422ComMimeInvalido(): void
    {
        $client = static::createClient();
        $user   = $this->criarUsuario();
        $this->instalarCsrfStorage();
        $client->loginUser($user);

        $tmpPath = sys_get_temp_dir() . '/test_txt_' . uniqid() . '.txt';
        file_put_contents($tmpPath, 'conteudo texto simples');
        $arquivo = new UploadedFile($tmpPath, 'foto.txt', 'text/plain', null, true);

        $client->request(
            'POST',
            '/perfil/foto',
            ['_token' => $this->gerarCsrf('profile_foto')],
            ['foto' => $arquivo],
        );

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('não permitido', $data['erro']);

        @unlink($tmpPath);
    }

    #[TestDox('POST /perfil/foto com JPEG válido retorna 200, persiste fotoUrl e devolve url')]
    public function testSalvarFotoComJpegValidoRetorna200EAtualizaFotoUrl(): void
    {
        $client = static::createClient();
        $user   = $this->criarUsuario();
        $this->instalarCsrfStorage();
        static::getContainer()->set(ArquivoStorageInterface::class, $this->storageFake('foto_teste.jpeg'));
        $client->loginUser($user);

        $tmpPath = $this->criarJpegTemporario();
        $arquivo = new UploadedFile($tmpPath, 'perfil.jpg', 'image/jpeg', null, true);

        $client->request(
            'POST',
            '/perfil/foto',
            ['_token' => $this->gerarCsrf('profile_foto')],
            ['foto' => $arquivo],
        );

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($data['ok']);
        self::assertStringContainsString('foto_teste.jpeg', $data['url']);

        $em         = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $userFresh  = $em->find(User::class, $user->getId());
        $repo       = static::getContainer()->get(UserProfileRepository::class);
        $perfil     = $repo->buscarPorUsuario($userFresh);

        self::assertNotNull($perfil, 'UserProfile deve existir após o upload');
        self::assertSame('foto_teste.jpeg', $perfil->getFotoUrl());

        @unlink($tmpPath);
    }
}
