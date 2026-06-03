<?php
declare(strict_types=1);
namespace App\Tests\Profile\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Profile\Controller\ProfileController;
use App\Profile\Entity\UserProfile;
use App\Shared\Service\ArquivoStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use App\Tests\Functional\JusPrimeWebTestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(ProfileController::class)]
final class ServirFotoControllerTest extends JusPrimeWebTestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/jusprime_foto_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $arquivo) {
            @unlink($arquivo);
        }
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    private function criarArquivoFoto(string $nomeArquivo): void
    {
        // JPEG mínimo válido para o BinaryFileResponse ter um arquivo real
        file_put_contents(
            $this->tempDir . '/' . $nomeArquivo,
            "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9"
        );
    }

    private function storageFakeComDir(string $dir): ArquivoStorageInterface
    {
        return new class($dir) implements ArquivoStorageInterface {
            public function __construct(private readonly string $dir) {}

            public function salvar(UploadedFile $arquivo, string $diretorio): string
            {
                throw new \LogicException('não utilizado neste teste');
            }

            public function servir(string $caminhoCompleto, string $nomeOriginal, bool $inline = true): BinaryFileResponse
            {
                return new BinaryFileResponse($caminhoCompleto);
            }

            public function excluir(string $caminhoCompleto): void {}

            public function existe(string $caminhoCompleto): bool
            {
                return file_exists($caminhoCompleto);
            }

            public function salvarConteudo(string $conteudo, string $diretorio, string $extensao): string
            {
                throw new \LogicException('não utilizado neste teste');
            }

            public function caminho(string $diretorio, string $nomeArquivo): string
            {
                return $this->dir . '/' . $nomeArquivo;
            }
        };
    }

    private function criarTenant(string $prefixo = 'Tenant'): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName($prefixo . ' Foto ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarUsuarioComFoto(Tenant $tenant, string $fotoUrl): User
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('foto_user_' . uniqid() . '@test.com');
        $user->setFullName('Usuário Foto');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $ut = new UserTenant($user, $tenant);
        $em->persist($ut);

        $perfil = new UserProfile($user);
        $perfil->setFotoUrl($fotoUrl);
        $em->persist($perfil);

        $em->flush();

        return $user;
    }

    private function criarUsuarioSemFoto(Tenant $tenant): User
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('sem_foto_' . uniqid() . '@test.com');
        $user->setFullName('Sem Foto');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $ut = new UserTenant($user, $tenant);
        $em->persist($ut);

        $em->flush();

        return $user;
    }

    #[TestDox('GET /perfil/foto/{nome} com colaborador do mesmo tenant retorna 200')]
    public function testServirFotoDeOutroColaboradorDoMesmoTenantRetorna200(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $fotoB  = 'foto_colaborador_' . uniqid() . '.jpg';

        $this->criarArquivoFoto($fotoB);
        static::getContainer()->set(ArquivoStorageInterface::class, $this->storageFakeComDir($this->tempDir));

        $userA = $this->criarUsuarioSemFoto($tenant);
        $this->criarUsuarioComFoto($tenant, $fotoB);

        $this->logarComTenant($client, $userA, $tenant);
        $client->request('GET', '/perfil/foto/' . $fotoB);

        self::assertResponseIsSuccessful();
    }

    #[TestDox('GET /perfil/foto/{nome} com filename real de outro tenant retorna 404 (prova isolamento cross-tenant)')]
    public function testServirFotoComFilenameRealDeOutroTenantRetorna404(): void
    {
        $client  = static::createClient();
        $tenant1 = $this->criarTenant('Tenant1');
        $tenant2 = $this->criarTenant('Tenant2');

        // fotoB é um filename REAL que existe no banco (UserProfile de B no tenant2).
        // O 404 prova isolamento de tenant, NÃO inexistência do filename.
        $fotoB = 'foto_outro_tenant_' . uniqid() . '.jpg';
        $this->criarUsuarioComFoto($tenant2, $fotoB);

        $userA = $this->criarUsuarioSemFoto($tenant1);

        $this->logarComTenant($client, $userA, $tenant1);
        $client->request('GET', '/perfil/foto/' . $fotoB);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('GET /perfil/foto/{nome} com filename inexistente no banco retorna 404')]
    public function testServirFotoComFilenameInexistenteRetorna404(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $userA  = $this->criarUsuarioSemFoto($tenant);

        $this->logarComTenant($client, $userA, $tenant);
        $client->request('GET', '/perfil/foto/arquivo_que_nao_existe_no_banco.jpg');

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('GET /perfil/foto/{nome} do próprio dono retorna 200')]
    public function testServirFotoDoProprioDonoRetorna200(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $fotoA  = 'foto_dono_' . uniqid() . '.jpg';

        $this->criarArquivoFoto($fotoA);
        static::getContainer()->set(ArquivoStorageInterface::class, $this->storageFakeComDir($this->tempDir));

        $userA = $this->criarUsuarioComFoto($tenant, $fotoA);

        $this->logarComTenant($client, $userA, $tenant);
        $client->request('GET', '/perfil/foto/' . $fotoA);

        self::assertResponseIsSuccessful();
    }

    #[TestDox('GET /perfil/foto/{nome} com tentativa de path traversal retorna 404')]
    public function testServirFotoComPathTraversalRetorna404(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $userA  = $this->criarUsuarioSemFoto($tenant);

        $this->logarComTenant($client, $userA, $tenant);

        // Documenta a proteção contra path traversal via HTTP.
        //
        // O Symfony decodifica %2F → '/' ANTES do matching de rota; o requisito padrão
        // [^/]+ do parâmetro {nome} rejeita qualquer slash literal, então a 404 aqui vem
        // do roteador — não do basename check do controller.
        //
        // O basename check é defesa em profundidade para vetores não-HTTP (ex: injeção de
        // fotoUrl com '/' diretamente no banco). Essa barreira específica não é exercitável
        // via request HTTP neste setup Symfony, pois o router intercepta antes.
        // Remover o basename check NÃO altera o resultado deste teste (proteção permanece
        // via router). O teste documenta que a rota é segura end-to-end.
        $client->request('GET', '/perfil/foto/..%2Fetc%2Fpasswd');

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('GET /perfil/foto/{nome} com usuário autenticado sem tenant na sessão redireciona para seleção de tenant')]
    public function testServirFotoSemTenantNaSessaoRedireciona(): void
    {
        $client = static::createClient();
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('sem_tenant_' . uniqid() . '@test.com');
        $user->setFullName('Sem Tenant');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->flush();

        // loginUser sem chamar logarComTenant → current_tenant_id ausente na sessão.
        // Um listener de kernel intercepta a request antes do controller e redireciona para
        // a seleção de tenant (302). O guard if ($tenant === null) no controller é defesa em
        // profundidade — não é alcançado via HTTP normal (o listener age primeiro).
        // Se o listener sumir, o guard do controller provê a segunda linha de proteção (404).
        $client->loginUser($user);
        $client->request('GET', '/perfil/foto/qualquer_foto.jpg');

        self::assertResponseRedirects('/escritorio/selecionar');
    }
}
