<?php
declare(strict_types=1);
namespace App\Tests\Profile\Functional;

use App\Entity\Auth\User;
use App\Profile\Controller\ProfileController;
use App\Profile\Repository\UserProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use App\Tests\Functional\JusPrimeWebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(ProfileController::class)]
final class SalvarFotoPerfilControllerTest extends JusPrimeWebTestCase
{
    /** @var list<string> arquivos criados no disco (o DAMA reverte o banco, não o disco) */
    private array $arquivosCriados = [];

    protected function tearDown(): void
    {
        foreach ($this->arquivosCriados as $caminho) {
            if (is_file($caminho)) {
                @unlink($caminho);
            }
        }

        $this->arquivosCriados = [];

        parent::tearDown();
    }

    private function criarUsuario(): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('test_foto_' . uniqid() . '@test.com');
        $user->setFullName('Usuário Foto');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
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
        $this->marcarTermosAceitos($client);

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
        $this->marcarTermosAceitos($client);

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
        $this->marcarTermosAceitos($client);

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

    #[TestDox('POST /perfil/foto com JPEG válido retorna 200, grava a foto no disco, persiste fotoUrl e devolve url')]
    public function testSalvarFotoComJpegValidoRetorna200EAtualizaFotoUrl(): void
    {
        $client = static::createClient();
        $user   = $this->criarUsuario();
        $this->instalarCsrfStorage();
        $client->loginUser($user);
        $this->marcarTermosAceitos($client);

        // Storage REAL do container: o nome é cunhado por ele, não fixado pelo teste.
        $tmpPath = $this->criarJpegTemporario();
        $this->arquivosCriados[] = $tmpPath;
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

        $em         = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $userFresh  = $em->find(User::class, $user->getId());
        $repo       = static::getContainer()->get(UserProfileRepository::class);
        $perfil     = $repo->buscarPorUsuario($userFresh);

        self::assertNotNull($perfil, 'UserProfile deve existir após o upload');
        $fotoUrl = (string) $perfil->getFotoUrl();
        $caminho = static::getContainer()->getParameter('fotos_perfil_dir') . '/' . $fotoUrl;
        $this->arquivosCriados[] = $caminho;

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.(jpg|jpeg)$/', $fotoUrl);
        self::assertStringEndsWith('/' . $fotoUrl, $data['url']);
        self::assertFileExists($caminho, 'a foto tem de estar em %fotos_perfil_dir%');
        self::assertStringEqualsFile($caminho, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9");
    }
}
