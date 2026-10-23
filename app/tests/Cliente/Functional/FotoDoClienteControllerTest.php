<?php

declare(strict_types=1);

namespace App\Tests\Cliente\Functional;

use App\Cliente\Controller\FotoDoClienteController;
use App\Cliente\Entity\ClientePF;
use App\Cliente\UseCase\RemoverFotoDoClienteUseCase;
use App\Cliente\UseCase\TrocarFotoDoClienteUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Foto do cliente pelo cabeçalho da pasta (desenho 02 VS 18.10.26, L1255 e L472-505): trocar e remover pela pasta, com
 * CSRF, permissão de EDITAR o cliente, escritório, cliente na pasta e lápide; a entrega para quem VÊ o cliente. Banco e
 * disco reais (DAMA desfaz o banco; os arquivos gravados são apagados no tearDown).
 */
#[CoversClass(FotoDoClienteController::class)]
#[CoversClass(TrocarFotoDoClienteUseCase::class)]
#[CoversClass(RemoverFotoDoClienteUseCase::class)]
final class FotoDoClienteControllerTest extends JusPrimeWebTestCase
{
    private const TRES_MB = 3 * 1024 * 1024;

    private KernelBrowser $client;
    private string $dirTemp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $storage = new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }
            public function setToken(string $tokenId, string $token): void
            {
            }
            public function removeToken(string $tokenId): ?string
            {
                return null;
            }
            public function hasToken(string $tokenId): bool
            {
                return true;
            }
            public function clear(): void
            {
            }
        };
        static::getContainer()->set('security.csrf.token_storage', $storage);
        $this->dirTemp = sys_get_temp_dir() . '/foto-cliente-func-' . bin2hex(random_bytes(6));
        mkdir($this->dirTemp, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dirTemp . '/*') ?: [] as $arquivo) {
            @unlink($arquivo);
        }
        @rmdir($this->dirTemp);
        foreach ($this->gravadosNoTeste as $caminho) {
            @unlink($caminho);
        }
        parent::tearDown();
    }

    /** @var list<string> */
    private array $gravadosNoTeste = [];

    #[TestDox('trocar: 200 {ok, url}; o cliente aponta para o arquivo gravado; Registro da pasta e Histórico do cadastro gravados')]
    public function testTrocar(): void
    {
        [$tenant, $gestor, $cliente, $pasta] = $this->cenario();
        $this->logarComTenant($this->client, $gestor, $tenant);

        $this->trocar($pasta, $cliente, $this->jpeg());

        self::assertResponseIsSuccessful();
        $j = $this->json();
        self::assertTrue($j['ok']);
        $nome = $this->fotoNoBanco($cliente);
        self::assertNotNull($nome);
        self::assertMatchesRegularExpression('#^/clientes/' . $cliente->getId() . '/foto/' . preg_quote($nome, '#') . '\?v=\d+$#', $j['url']);
        self::assertFileExists($this->caminho($nome));

        $conn = $this->em()->getConnection();
        $cartao = (string) $conn->fetchOne("SELECT conteudo FROM pasta_mensagem WHERE pasta_id = ? AND sistema = 'cadastro'", [$pasta->getId()]);
        self::assertStringContainsString('Cadastro do cliente:', $cartao);
        self::assertStringContainsString('Foto do cliente alterada', $cartao);
        $linhas = $conn->fetchFirstColumn('SELECT texto FROM cliente_historico WHERE cliente_id = ?', [$cliente->getId()]);
        self::assertSame([$gestor->getFullName() . ' alterou foto'], $linhas);
    }

    #[TestDox('trocar de novo: a foto anterior sai do disco depois do COMMIT e a nova é entregue inline')]
    public function testTrocarDeNovoApagaAAnteriorEEntrega(): void
    {
        [$tenant, $gestor, $cliente, $pasta] = $this->cenario();
        $this->logarComTenant($this->client, $gestor, $tenant);

        $this->trocar($pasta, $cliente, $this->jpeg());
        $primeira = (string) $this->fotoNoBanco($cliente);
        $this->trocar($pasta, $cliente, $this->png());
        $segunda = (string) $this->fotoNoBanco($cliente);
        $url = $this->json()['url'];

        self::assertNotSame($primeira, $segunda);
        self::assertFileDoesNotExist($this->caminho($primeira));
        self::assertFileExists($this->caminho($segunda));

        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('image/png', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringStartsWith('inline', (string) $this->client->getResponse()->headers->get('Content-Disposition'));

        $this->client->request('GET', '/clientes/' . $cliente->getId() . '/foto/' . $primeira);
        self::assertResponseStatusCodeSame(404, 'o nome que o cliente já não guarda não é entregue');
    }

    #[TestDox('remover: 200 {ok, url: null}; o arquivo sai do disco; Registro e Histórico gravados')]
    public function testRemover(): void
    {
        [$tenant, $gestor, $cliente, $pasta] = $this->cenario();
        $this->logarComTenant($this->client, $gestor, $tenant);
        $this->trocar($pasta, $cliente, $this->jpeg());
        $nome = (string) $this->fotoNoBanco($cliente);

        $this->remover($pasta, $cliente);

        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true, 'url' => null], $this->json());
        self::assertNull($this->fotoNoBanco($cliente));
        self::assertFileDoesNotExist($this->caminho($nome));
        $conn = $this->em()->getConnection();
        self::assertStringContainsString('Foto do cliente removida', (string) $conn->fetchOne("SELECT conteudo FROM pasta_mensagem WHERE pasta_id = ? AND sistema = 'cadastro' ORDER BY id DESC LIMIT 1", [$pasta->getId()]));
        self::assertContains($gestor->getFullName() . ' removeu foto', $conn->fetchFirstColumn('SELECT texto FROM cliente_historico WHERE cliente_id = ?', [$cliente->getId()]));
    }

    #[TestDox('CSRF inválido: 403 e nada muda')]
    public function testCsrf(): void
    {
        [$tenant, $gestor, $cliente, $pasta] = $this->cenario();
        $this->logarComTenant($this->client, $gestor, $tenant);

        $this->trocar($pasta, $cliente, $this->jpeg(), 'errado');
        self::assertResponseStatusCodeSame(403);
        $this->remover($pasta, $cliente, 'errado');
        self::assertResponseStatusCodeSame(403);

        self::assertNull($this->fotoNoBanco($cliente));
    }

    #[TestDox('tipo e tamanho: GIF, PDF e JPEG acima de 3 MB são 422, sem gravar')]
    public function testTipoETamanho(): void
    {
        [$tenant, $gestor, $cliente, $pasta] = $this->cenario();
        $this->logarComTenant($this->client, $gestor, $tenant);

        $gif = "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;";
        foreach ([$gif, "%PDF-1.4\n%%EOF\n", str_pad($this->jpeg(), self::TRES_MB + 10, "\0")] as $conteudo) {
            $this->trocar($pasta, $cliente, $conteudo);
            self::assertResponseStatusCodeSame(422);
            self::assertArrayHasKey('erro', $this->json());
        }

        self::assertNull($this->fotoNoBanco($cliente));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM pasta_mensagem WHERE pasta_id = ? AND sistema = 'cadastro'", [$pasta->getId()]));
    }

    #[TestDox('quem só VÊ o cliente: trocar e remover são 403, mas a foto é entregue')]
    public function testSoVer(): void
    {
        [$tenant, $gestor, $cliente, $pasta] = $this->cenario();
        $this->logarComTenant($this->client, $gestor, $tenant);
        $this->trocar($pasta, $cliente, $this->jpeg());
        $url = $this->json()['url'];

        $leitor = $this->criarColaborador($tenant, ['resources.pasta.view', 'resources.cliente.view']);
        $this->logarComTenant($this->client, $leitor, $tenant);

        $this->trocar($pasta, $cliente, $this->png());
        self::assertResponseStatusCodeSame(403);
        $this->remover($pasta, $cliente);
        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->fotoNoBanco($cliente));

        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
    }

    #[TestDox('quem pode editar o cliente pelo papel troca a foto; quem não vê o cliente não recebe a foto (404)')]
    public function testEditorEVisibilidade(): void
    {
        [$tenant, , $cliente, $pasta] = $this->cenario();
        $editor = $this->criarColaborador($tenant, ['resources.pasta.view', 'resources.cliente.view', 'resources.cliente.edit']);
        $this->logarComTenant($this->client, $editor, $tenant);

        $this->trocar($pasta, $cliente, $this->jpeg());
        self::assertResponseIsSuccessful();
        $url = $this->json()['url'];

        $semVer = $this->criarColaborador($tenant, ['resources.pasta.view']);
        $this->logarComTenant($this->client, $semVer, $tenant);
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('escritório: pasta de outro escritório, cliente de outro escritório e foto de outro escritório são 404 (IDOR)')]
    public function testOutroEscritorio(): void
    {
        [$tenantA, $gestorA, $clienteA, $pastaA] = $this->cenario();
        [$tenantB, $gestorB, $clienteB, $pastaB] = $this->cenario();
        $this->logarComTenant($this->client, $gestorB, $tenantB);
        $this->trocar($pastaB, $clienteB, $this->jpeg());
        $urlDoB = $this->json()['url'];

        $this->logarComTenant($this->client, $gestorA, $tenantA);
        $this->trocar($pastaB, $clienteB, $this->png());
        self::assertResponseStatusCodeSame(404, 'pasta e cliente do B pela URL');
        $this->trocar($pastaA, $clienteB, $this->png());
        self::assertResponseStatusCodeSame(404, 'cliente do B pela pasta do A');
        $this->remover($pastaB, $clienteB);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $urlDoB);
        self::assertResponseStatusCodeSame(404, 'a foto do B não sai para o A');

        self::assertNull($this->fotoNoBanco($clienteA));
        self::assertNotNull($this->fotoNoBanco($clienteB));
    }

    #[TestDox('cliente do escritório que não está nesta pasta: 404')]
    public function testClienteForaDaPasta(): void
    {
        [$tenant, $gestor, , $pasta] = $this->cenario();
        $outro = $this->criarClientePF($tenant, 'Fora da Pasta');
        $this->logarComTenant($this->client, $gestor, $tenant);

        $this->trocar($pasta, $outro, $this->jpeg());

        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->fotoNoBanco($outro));
    }

    #[TestDox('pasta excluída (lápide): 403 com a mensagem de somente leitura, e nada muda')]
    public function testLapide(): void
    {
        [$tenant, $gestor, $cliente, $pasta] = $this->cenario();
        $this->logarComTenant($this->client, $gestor, $tenant);
        $this->trocar($pasta, $cliente, $this->jpeg());
        $nome = $this->fotoNoBanco($cliente);
        $pasta = $this->em()->find(Pasta::class, $pasta->getId());
        self::assertNotNull($pasta);
        $pasta->marcarExcluida($this->em()->getReference(User::class, $gestor->getId()), new \DateTimeImmutable());
        $this->em()->flush();

        $this->trocar($pasta, $cliente, $this->png());
        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('somente para leitura', (string) ($this->json()['mensagem'] ?? $this->json()['erro'] ?? ''));
        $this->remover($pasta, $cliente);
        self::assertResponseStatusCodeSame(403);

        self::assertSame($nome, $this->fotoNoBanco($cliente));
    }

    #[TestDox('entrega: nome com caminho e nome que não é o do cliente são 404')]
    public function testEntregaConfereONome(): void
    {
        [$tenant, $gestor, $cliente, $pasta] = $this->cenario();
        $this->logarComTenant($this->client, $gestor, $tenant);
        $this->trocar($pasta, $cliente, $this->jpeg());

        $this->client->request('GET', '/clientes/' . $cliente->getId() . '/foto/..%2F..%2Fsegredo.jpg');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/clientes/' . $cliente->getId() . '/foto/' . bin2hex(random_bytes(16)) . '.jpg');
        self::assertResponseStatusCodeSame(404);
    }

    // ---------------------------------------------------------------- helpers

    /** @return array{0: Tenant, 1: User, 2: ClientePF, 3: Pasta} */
    private function cenario(): array
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant);
        $cliente = $this->criarClientePF($tenant, 'Gleisson Bruno');
        $pasta = new Pasta();
        $pasta->setNup('FOTO-' . strtoupper(substr(uniqid(), -6)));
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($gestor);
        $pasta->setResponsavel($gestor);
        $pasta->addCliente($cliente);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return [$tenant, $gestor, $cliente, $pasta];
    }

    private function trocar(Pasta $pasta, ClientePF $cliente, string $conteudo, ?string $token = null): void
    {
        $caminho = $this->dirTemp . '/' . bin2hex(random_bytes(6));
        file_put_contents($caminho, $conteudo);
        $this->em()->clear();
        $this->client->request(
            'POST',
            '/pasta/' . $pasta->getId() . '/clientes/' . $cliente->getId() . '/foto',
            ['_token' => $token ?? 'TOKEN_cliente_foto_' . $cliente->getId()],
            ['foto' => new UploadedFile($caminho, 'foto.jpg', null, null, true)],
            ['HTTP_X-Requested-With' => 'XMLHttpRequest'],
        );
        $nome = $this->fotoNoBanco($cliente);
        if ($nome !== null) {
            $this->gravadosNoTeste[] = $this->caminho($nome);
        }
    }

    private function remover(Pasta $pasta, ClientePF $cliente, ?string $token = null): void
    {
        $this->em()->clear();
        $this->client->xmlHttpRequest('POST', '/pasta/' . $pasta->getId() . '/clientes/' . $cliente->getId() . '/foto/remover', [
            '_token' => $token ?? 'TOKEN_cliente_foto_' . $cliente->getId(),
        ]);
    }

    private function fotoNoBanco(ClientePF $cliente): ?string
    {
        $nome = $this->em()->getConnection()->fetchOne('SELECT foto FROM cliente WHERE id = ?', [$cliente->getId()]);

        return \is_string($nome) ? $nome : null;
    }

    private function caminho(string $nome): string
    {
        return rtrim((string) static::getContainer()->getParameter('clientes_uploads_dir'), '/') . '/fotos/' . $nome;
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function jpeg(): string
    {
        ob_start();
        imagejpeg(imagecreatetruecolor(8, 8), null, 88);

        return (string) ob_get_clean();
    }

    private function png(): string
    {
        ob_start();
        imagepng(imagecreatetruecolor(8, 8));

        return (string) ob_get_clean();
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Foto ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    private function criarClientePF(Tenant $tenant, string $nome): ClientePF
    {
        $cliente = new ClientePF();
        $cliente->setNomeCompleto($nome);
        $cliente->setCpf((string) random_int(10000000000, 99999999999));
        $cliente->setRg('1234567');
        $cliente->setRgOrgaoExpedidor('SSP/DF');
        $cliente->setEmail('foto_' . uniqid() . '@test.com');
        $cliente->setCep('70000-000');
        $cliente->setEndereco('SQS 110 Bloco A');
        $cliente->setCidade('Brasília');
        $cliente->setEstado('DF');
        $cliente->setTenant($tenant);
        $this->em()->persist($cliente);
        $this->em()->flush();

        return $cliente;
    }

    private function novoUsuario(string $prefixo): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail($prefixo . '_' . uniqid() . '@test.com');
        $user->setFullName(ucfirst($prefixo) . ' ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em()->persist($user);

        return $user;
    }

    private function criarGestor(Tenant $tenant): User
    {
        $user = $this->novoUsuario('gestor');
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Gestor ' . uniqid());
        $role->setIsSystem(true);
        $this->em()->persist($role);
        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $this->em()->persist($ut);
        $this->em()->flush();

        return $user;
    }

    /** @param list<string> $codigos permissões do papel (ex.: resources.cliente.view) */
    private function criarColaborador(Tenant $tenant, array $codigos): User
    {
        $em = $this->em();
        $tenant = $em->getReference(Tenant::class, $tenant->getId()); // o cenário pode ter passado por um clear()
        $user = $this->novoUsuario('colab');
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Colaborador ' . uniqid());
        $role->setIsSystem(false);
        $em->persist($role);
        foreach ($codigos as $codigo) {
            $perm = $em->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
            if ($perm === null) {
                $perm = new Permission();
                $perm->setCode($codigo);
                $perm->setDescription($codigo);
                $perm->setGroup('resources');
                $em->persist($perm);
            }
            $vinculo = new TenantRolePermission();
            $vinculo->setTenantRole($role);
            $vinculo->setPermission($perm);
            $em->persist($vinculo);
            $role->getTenantRolePermissions()->add($vinculo);
        }
        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $em->persist($ut);
        $em->flush();

        return $user;
    }
}
