<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Cliente\Entity\ClientePF;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Pasta\Controller\FotoDaPastaController;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Pasta\UseCase\RemoverFotoDaPastaUseCase;
use App\Pasta\UseCase\TrocarFotoDaPastaUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Foto do cabeçalho da pasta SEM cliente cadastrado (desenho 02 VS 18.10.26, L1255 e L472-505): trocar e remover pela
 * pasta, com CSRF, permissão de EDITAR a pasta, escritório, lápide e cadeado; a entrega para quem VÊ a pasta; e a
 * regra de exibição com cliente vinculado depois (a foto da pasta vale até o cliente ter foto própria). Banco e disco
 * reais (DAMA desfaz o banco; os arquivos gravados são apagados no tearDown).
 */
#[CoversClass(FotoDaPastaController::class)]
#[CoversClass(TrocarFotoDaPastaUseCase::class)]
#[CoversClass(RemoverFotoDaPastaUseCase::class)]
final class FotoDaPastaControllerTest extends JusPrimeWebTestCase
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
        $this->dirTemp = sys_get_temp_dir() . '/foto-pasta-func-' . bin2hex(random_bytes(6));
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

    #[TestDox('trocar: 200 {ok, url, origem: pasta}; a pasta aponta para o arquivo gravado; Registro da pasta gravado')]
    public function testTrocar(): void
    {
        [$tenant, $gestor, $pasta] = $this->cenario();
        $this->logarComTenant($this->client, $gestor, $tenant);

        $this->trocar($pasta, $this->jpeg());

        self::assertResponseIsSuccessful();
        $j = $this->json();
        self::assertTrue($j['ok']);
        self::assertSame('pasta', $j['origem']);
        $nome = $this->fotoNoBanco($pasta);
        self::assertNotNull($nome);
        self::assertMatchesRegularExpression('#^/pasta/' . $pasta->getId() . '/foto/' . preg_quote($nome, '#') . '\?v=\d+$#', $j['url']);
        self::assertFileExists($this->caminho($nome));
        $cartao = (string) $this->em()->getConnection()->fetchOne("SELECT conteudo FROM pasta_mensagem WHERE pasta_id = ? AND sistema = 'pasta'", [$pasta->getId()]);
        self::assertStringContainsString('Dados da pasta:', $cartao);
        self::assertStringContainsString('Foto do cliente alterada', $cartao);
    }

    #[TestDox('trocar de novo: a foto anterior sai do disco depois do COMMIT e a nova é entregue inline')]
    public function testTrocarDeNovoApagaAAnteriorEEntrega(): void
    {
        [$tenant, $gestor, $pasta] = $this->cenario();
        $this->logarComTenant($this->client, $gestor, $tenant);

        $this->trocar($pasta, $this->jpeg());
        $primeira = (string) $this->fotoNoBanco($pasta);
        $this->trocar($pasta, $this->png());
        $segunda = (string) $this->fotoNoBanco($pasta);
        $url = $this->json()['url'];

        self::assertNotSame($primeira, $segunda);
        self::assertFileDoesNotExist($this->caminho($primeira));
        self::assertFileExists($this->caminho($segunda));
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('image/png', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringStartsWith('inline', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $this->client->request('GET', '/pasta/' . $pasta->getId() . '/foto/' . $primeira);
        self::assertResponseStatusCodeSame(404, 'o nome que a pasta já não guarda não é entregue');
    }

    #[TestDox('remover: 200 {ok, url: null, origem: null}; o arquivo sai do disco; Registro gravado')]
    public function testRemover(): void
    {
        [$tenant, $gestor, $pasta] = $this->cenario();
        $this->logarComTenant($this->client, $gestor, $tenant);
        $this->trocar($pasta, $this->jpeg());
        $nome = (string) $this->fotoNoBanco($pasta);

        $this->remover($pasta);

        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true, 'url' => null, 'origem' => null], $this->json());
        self::assertNull($this->fotoNoBanco($pasta));
        self::assertFileDoesNotExist($this->caminho($nome));
        self::assertStringContainsString('Foto do cliente removida', (string) $this->em()->getConnection()->fetchOne("SELECT conteudo FROM pasta_mensagem WHERE pasta_id = ? AND sistema = 'pasta' ORDER BY id DESC LIMIT 1", [$pasta->getId()]));
    }

    #[TestDox('CSRF inválido (inclusive o token da foto do cliente): 403 e nada muda')]
    public function testCsrf(): void
    {
        [$tenant, $gestor, $pasta] = $this->cenario();
        $this->logarComTenant($this->client, $gestor, $tenant);

        $this->trocar($pasta, $this->jpeg(), 'errado');
        self::assertResponseStatusCodeSame(403);
        $this->trocar($pasta, $this->jpeg(), 'TOKEN_cliente_foto_' . $pasta->getId());
        self::assertResponseStatusCodeSame(403);
        $this->remover($pasta, 'errado');
        self::assertResponseStatusCodeSame(403);

        self::assertNull($this->fotoNoBanco($pasta));
    }

    #[TestDox('tipo e tamanho: GIF, PDF e JPEG acima de 3 MB são 422, sem gravar')]
    public function testTipoETamanho(): void
    {
        [$tenant, $gestor, $pasta] = $this->cenario();
        $this->logarComTenant($this->client, $gestor, $tenant);

        $gif = "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;";
        foreach ([$gif, "%PDF-1.4\n%%EOF\n", str_pad($this->jpeg(), self::TRES_MB + 10, "\0")] as $conteudo) {
            $this->trocar($pasta, $conteudo);
            self::assertResponseStatusCodeSame(422);
            self::assertArrayHasKey('erro', $this->json());
        }

        self::assertNull($this->fotoNoBanco($pasta));
    }

    #[TestDox('quem só VÊ a pasta: trocar e remover são 403, mas a foto é entregue; quem edita pelo papel troca')]
    public function testSoVerEEditorPeloPapel(): void
    {
        [$tenant, $gestor, $pasta] = $this->cenario();
        $editor = $this->criarColaborador($tenant, ['resources.pasta.view', 'resources.pasta.edit']);
        $this->logarComTenant($this->client, $editor, $tenant);
        $this->trocar($pasta, $this->jpeg());
        self::assertResponseIsSuccessful();
        $url = $this->json()['url'];

        $leitor = $this->criarColaborador($tenant, ['resources.pasta.view']);
        $this->logarComTenant($this->client, $leitor, $tenant);
        $this->trocar($pasta, $this->png());
        self::assertResponseStatusCodeSame(403);
        $this->remover($pasta);
        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->fotoNoBanco($pasta));
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
    }

    #[TestDox('escritório: trocar, remover e a foto da pasta de outro escritório são 404 (IDOR)')]
    public function testOutroEscritorio(): void
    {
        [$tenantA, $gestorA, $pastaA] = $this->cenario();
        [$tenantB, $gestorB, $pastaB] = $this->cenario();
        $this->logarComTenant($this->client, $gestorB, $tenantB);
        $this->trocar($pastaB, $this->jpeg());
        $urlDoB = $this->json()['url'];
        $nomeDoB = $this->fotoNoBanco($pastaB);

        $this->logarComTenant($this->client, $gestorA, $tenantA);
        $this->trocar($pastaB, $this->png());
        self::assertResponseStatusCodeSame(404, 'pasta do B pela URL');
        $this->remover($pastaB);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $urlDoB);
        self::assertResponseStatusCodeSame(404, 'a foto do B não sai para o A');
        $this->client->request('GET', '/pasta/' . $pastaA->getId() . '/foto/' . $nomeDoB);
        self::assertResponseStatusCodeSame(404, 'o nome do B pela pasta do A');

        self::assertNull($this->fotoNoBanco($pastaA));
        self::assertSame($nomeDoB, $this->fotoNoBanco($pastaB));
    }

    #[TestDox('pasta excluída (lápide) e cadeado em Visualizar: 403, e nada muda')]
    public function testLapideECadeado(): void
    {
        [$tenant, $gestor, $pasta] = $this->cenario();
        $editor = $this->criarColaborador($tenant, ['resources.pasta.view', 'resources.pasta.edit']);
        $this->trancar($tenant, $pasta, $editor, NivelDeAcesso::Visualizar);
        $this->logarComTenant($this->client, $editor, $tenant);
        $this->trocar($pasta, $this->jpeg());
        self::assertResponseStatusCodeSame(403, 'cadeado em Visualizar');

        $this->logarComTenant($this->client, $gestor, $tenant);
        $pasta = $this->em()->find(Pasta::class, $pasta->getId());
        self::assertNotNull($pasta);
        $pasta->marcarExcluida($this->em()->getReference(User::class, $gestor->getId()), new \DateTimeImmutable());
        $this->em()->flush();
        $this->trocar($pasta, $this->png());
        self::assertResponseStatusCodeSame(403);
        $this->remover($pasta);
        self::assertResponseStatusCodeSame(403);

        self::assertNull($this->fotoNoBanco($pasta));
    }

    #[TestDox('cliente vinculado depois: a foto da pasta continua até o cliente ter a dele; removida a do cliente, a da pasta volta')]
    public function testClienteVinculadoDepois(): void
    {
        [$tenant, $gestor, $pasta] = $this->cenario();
        $this->logarComTenant($this->client, $gestor, $tenant);
        $this->trocar($pasta, $this->jpeg());
        $urlDaPasta = $this->json()['url'];
        $cliente = $this->vincularCliente($pasta, $tenant);

        $this->trocar($pasta, $this->png());
        self::assertResponseStatusCodeSame(403, 'com cliente, a foto nova vai para o cliente');

        $this->em()->clear();
        $img = $this->client->request('GET', '/pasta/' . $pasta->getId())->filter('.ps-cab-cliente > button.js-ps-foto > img.ps-cab-foto-img');
        self::assertSame($urlDaPasta, $img->attr('src'), 'cliente sem foto: a da pasta aparece');

        $this->trocarDoCliente($pasta, $cliente);
        self::assertSame('cliente', $this->json()['origem']);
        $this->client->xmlHttpRequest('POST', '/pasta/' . $pasta->getId() . '/clientes/' . $cliente->getId() . '/foto/remover', ['_token' => 'TOKEN_cliente_foto_' . $cliente->getId()]);
        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true, 'url' => $urlDaPasta, 'origem' => 'pasta'], $this->json());
    }

    #[TestDox('entrega: nome com caminho e nome que não é o da pasta são 404')]
    public function testEntregaConfereONome(): void
    {
        [$tenant, $gestor, $pasta] = $this->cenario();
        $this->logarComTenant($this->client, $gestor, $tenant);
        $this->trocar($pasta, $this->jpeg());

        $this->client->request('GET', '/pasta/' . $pasta->getId() . '/foto/..%2F..%2Fsegredo.jpg');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/pasta/' . $pasta->getId() . '/foto/' . bin2hex(random_bytes(16)) . '.jpg');
        self::assertResponseStatusCodeSame(404);
    }

    // ---------------------------------------------------------------- helpers

    /** @return array{0: Tenant, 1: User, 2: Pasta} */
    private function cenario(): array
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant);
        $pasta = new Pasta();
        $pasta->setNup('FOTO-' . strtoupper(substr(uniqid(), -6)));
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($gestor);
        $pasta->setResponsavel($gestor);
        $pasta->setNomeCliente('Gleisson Bruno');
        $this->em()->persist($pasta);
        $this->em()->flush();

        return [$tenant, $gestor, $pasta];
    }

    private function trocar(Pasta $pasta, string $conteudo, ?string $token = null): void
    {
        $this->em()->clear();
        $this->client->request(
            'POST',
            '/pasta/' . $pasta->getId() . '/foto',
            ['_token' => $token ?? 'TOKEN_pasta_foto_' . $pasta->getId()],
            ['foto' => $this->upload($conteudo)],
            ['HTTP_X-Requested-With' => 'XMLHttpRequest'],
        );
        $nome = $this->fotoNoBanco($pasta);
        if ($nome !== null) {
            $this->gravadosNoTeste[] = $this->caminho($nome);
        }
    }

    private function trocarDoCliente(Pasta $pasta, ClientePF $cliente): void
    {
        $this->em()->clear();
        $this->client->request(
            'POST',
            '/pasta/' . $pasta->getId() . '/clientes/' . $cliente->getId() . '/foto',
            ['_token' => 'TOKEN_cliente_foto_' . $cliente->getId()],
            ['foto' => $this->upload($this->jpeg())],
            ['HTTP_X-Requested-With' => 'XMLHttpRequest'],
        );
        self::assertResponseIsSuccessful();
        $nome = $this->em()->getConnection()->fetchOne('SELECT foto FROM cliente WHERE id = ?', [$cliente->getId()]);
        if (\is_string($nome)) {
            $this->gravadosNoTeste[] = rtrim((string) static::getContainer()->getParameter('clientes_uploads_dir'), '/') . '/fotos/' . $nome;
        }
    }

    private function upload(string $conteudo): UploadedFile
    {
        $caminho = $this->dirTemp . '/' . bin2hex(random_bytes(6));
        file_put_contents($caminho, $conteudo);

        return new UploadedFile($caminho, 'foto.jpg', null, null, true);
    }

    private function remover(Pasta $pasta, ?string $token = null): void
    {
        $this->em()->clear();
        $this->client->xmlHttpRequest('POST', '/pasta/' . $pasta->getId() . '/foto/remover', [
            '_token' => $token ?? 'TOKEN_pasta_foto_' . $pasta->getId(),
        ]);
    }

    private function vincularCliente(Pasta $pasta, Tenant $tenant): ClientePF
    {
        $em = $this->em();
        $em->clear();
        $cliente = new ClientePF();
        $cliente->setNomeCompleto('Gleisson Bruno');
        $cliente->setCpf((string) random_int(10000000000, 99999999999));
        $cliente->setRg('1234567');
        $cliente->setRgOrgaoExpedidor('SSP/DF');
        $cliente->setEmail('foto_' . uniqid() . '@test.com');
        $cliente->setCep('70000-000');
        $cliente->setEndereco('SQS 110 Bloco A');
        $cliente->setCidade('Brasília');
        $cliente->setEstado('DF');
        $cliente->setTenant($em->getReference(Tenant::class, $tenant->getId()));
        $em->persist($cliente);
        $daPasta = $em->find(Pasta::class, $pasta->getId());
        self::assertNotNull($daPasta);
        $daPasta->addCliente($cliente);
        $em->flush();

        return $cliente;
    }

    private function trancar(Tenant $tenant, Pasta $pasta, User $pessoa, NivelDeAcesso $nivel): void
    {
        $em = $this->em();
        $cadeado = new CadeadoDaPasta(
            $em->getReference(Tenant::class, $tenant->getId()),
            $em->getReference(Pasta::class, $pasta->getId()),
            ModoDoCadeado::PorPessoa,
            null,
            new \DateTimeImmutable(),
        );
        $cadeado->definirPorPessoa([[$em->getReference(User::class, $pessoa->getId()), $nivel]], null, new \DateTimeImmutable());
        $em->persist($cadeado);
        $em->flush();
    }

    private function fotoNoBanco(Pasta $pasta): ?string
    {
        $nome = $this->em()->getConnection()->fetchOne('SELECT foto FROM pasta WHERE id = ?', [$pasta->getId()]);

        return \is_string($nome) ? $nome : null;
    }

    private function caminho(string $nome): string
    {
        return rtrim((string) static::getContainer()->getParameter('uploads_dir'), '/') . '/fotos/' . $nome;
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
