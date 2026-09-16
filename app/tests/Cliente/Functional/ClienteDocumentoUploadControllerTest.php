<?php

declare(strict_types=1);

namespace App\Tests\Cliente\Functional;

use App\Cliente\Armazenamento\ChavesDeCliente;
use App\Cliente\Controller\ClienteController;
use App\Cliente\Entity\ClienteDocumento;
use App\Cliente\Entity\ClientePF;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoriaNoContainer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Upload de documento na ficha do cliente (`POST /clientes/{id}/documento/upload`), contra o
 * storage e o diretório REAIS (`var/uploads-test/clientes` em teste). O DAMA reverte o banco, não
 * o disco: todo arquivo que surgir no diretório durante a requisição é removido no tearDown.
 *
 * Usa gestor isSystem (bypassa o PermissionChecker) e o storage de CSRF determinístico
 * (`TOKEN_<id>`) — o que está sob teste é a gravação, não a permissão.
 */
#[CoversClass(ClienteController::class)]
final class ClienteDocumentoUploadControllerTest extends JusPrimeWebTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    /** @var list<string> */
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

    #[TestDox('Upload de PDF grava o arquivo em %clientes_uploads_dir% e registra o documento com nome e MIME')]
    public function testUploadDePdfGravaArquivoERegistraDocumento(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant  = $this->criarTenant();
        $gestor  = $this->criarGestor($tenant);
        $cliente = $this->criarClientePF($tenant);
        $id      = (int) $cliente->getId();
        $this->limparIdentityMap();

        $this->logarComTenant($client, $gestor, $tenant);

        $this->enviar($client, $id, self::PDF, 'rg-frente.pdf', ClienteDocumento::CATEGORIA_IDENTIFICACAO);

        self::assertResponseRedirects('/clientes/' . $id);

        $documentos = $this->documentosDoCliente($id);
        self::assertCount(1, $documentos, 'o documento deve ter sido registrado');

        $doc     = $documentos[0];
        $caminho = $this->diretorio() . '/' . $doc->getCaminhoArquivo();

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.pdf$/', $doc->getCaminhoArquivo());
        self::assertFileExists($caminho, 'o documento tem de estar em %clientes_uploads_dir%');
        self::assertStringEqualsFile($caminho, self::PDF);
        self::assertSame('rg-frente.pdf', $doc->getNomeOriginal());
        self::assertSame('application/pdf', $doc->getMimeType());
        self::assertSame(\strlen(self::PDF), $doc->getTamanhoBytes());
        self::assertSame(ClienteDocumento::CATEGORIA_IDENTIFICACAO, $doc->getCategoria());
        self::assertSame($tenant->getId(), $doc->getTenant()?->getId());
    }

    #[TestDox('Tipo fora da whitelist é recusado sem gravar arquivo nem registrar documento')]
    public function testTipoNaoPermitidoNaoGrava(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant  = $this->criarTenant();
        $gestor  = $this->criarGestor($tenant);
        $cliente = $this->criarClientePF($tenant);
        $id      = (int) $cliente->getId();
        $this->limparIdentityMap();

        $this->logarComTenant($client, $gestor, $tenant);

        // Cabeçalho MZ: executável do Windows.
        $novos = $this->enviar($client, $id, "MZ\x90\x00\x03\x00\x00\x00" . str_repeat("\0", 200), 'setup.exe');

        self::assertResponseRedirects('/clientes/' . $id);
        self::assertSame([], $novos, 'a recusa tem de acontecer antes de qualquer gravação');
        self::assertCount(0, $this->documentosDoCliente($id));
    }

    #[TestDox('Upload no cliente de outro escritório retorna 404 sem gravar arquivo nem registrar documento')]
    public function testUploadEmClienteDeOutroTenantRetorna404SemGravar(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenantA  = $this->criarTenant();
        $tenantB  = $this->criarTenant();
        $gestorA  = $this->criarGestor($tenantA);
        $clienteB = $this->criarClientePF($tenantB);
        $idB      = (int) $clienteB->getId();
        $this->limparIdentityMap();

        $this->logarComTenant($client, $gestorA, $tenantA);

        $novos = $this->enviar($client, $idB, self::PDF, 'intruso.pdf');

        self::assertResponseStatusCodeSame(404, 'upload não pode alcançar cliente de outro tenant');
        self::assertSame([], $novos, 'nada pode ser gravado para o cliente de outro tenant');
        self::assertCount(0, $this->documentosDoCliente($idB));
    }

    /**
     * R1: no disco o escopo da chave não aparece (a categoria é plana). Contra o dublê em memória,
     * a chave gravada tem de ser exatamente a que a leitura monta a partir do documento persistido.
     */
    #[TestDox('R1: a chave gravada é a mesma que a leitura monta a partir do documento')]
    public function testChaveGravadaEhAMesmaDaLeitura(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $duble   = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $tenant  = $this->criarTenant();
        $gestor  = $this->criarGestor($tenant);
        $cliente = $this->criarClientePF($tenant);
        $id      = (int) $cliente->getId();
        $this->limparIdentityMap();

        $this->logarComTenant($client, $gestor, $tenant);

        $this->enviar($client, $id, self::PDF, 'rg-frente.pdf');

        self::assertResponseRedirects('/clientes/' . $id);
        $documentos = $this->documentosDoCliente($id);
        self::assertCount(1, $documentos);

        $gravada = $duble->memoria->ultimaGravada();
        self::assertTrue($gravada->ehIgualA(ChavesDeCliente::documento($documentos[0])), 'gravação e leitura divergem');
        self::assertSame(CategoriaDeArquivo::CLIENTE_DOCUMENTO, $gravada->categoria);
        self::assertSame($tenant->getId(), $gravada->escopo->tenantIdOuNull());
    }

    #[TestDox('falha do storage: nenhum documento é registrado')]
    public function testFalhaDoStorageNaoRegistraDocumento(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $duble                         = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $duble->memoria->falhaAoGravar = new FalhaDeArmazenamento('disco cheio');
        $tenant                        = $this->criarTenant();
        $gestor                        = $this->criarGestor($tenant);
        $cliente                       = $this->criarClientePF($tenant);
        $id                            = (int) $cliente->getId();
        $this->limparIdentityMap();

        $this->logarComTenant($client, $gestor, $tenant);

        $this->enviar($client, $id, self::PDF, 'rg-frente.pdf');

        self::assertResponseStatusCodeSame(500);
        self::assertStringContainsString('disco cheio', (string) $client->getResponse()->getContent(), 'o 500 tem de ser a falha de gravação, não outra');
        self::assertCount(0, $this->documentosDoCliente($id));
    }

    // ----------------------------------------------------------------- helpers

    /**
     * Envia um arquivo pela rota e devolve os nomes que surgiram no diretório durante a
     * requisição (já registrados para limpeza).
     *
     * @return list<string>
     */
    private function enviar(
        KernelBrowser $client,
        int $clienteId,
        string $conteudo,
        string $nomeOriginal,
        string $categoria = ClienteDocumento::CATEGORIA_DEMAIS,
    ): array {
        $origem = sys_get_temp_dir() . '/cliente_doc_' . bin2hex(random_bytes(6));
        file_put_contents($origem, $conteudo);
        $this->arquivosCriados[] = $origem;

        $antes = $this->arquivosNoDiretorio();

        $client->request(
            'POST',
            "/clientes/{$clienteId}/documento/upload",
            [
                '_token'     => 'TOKEN_upload_documento_cliente_' . $clienteId,
                'categorias' => [0 => $categoria],
                'descricoes' => [0 => 'Documento de teste'],
                'numeros'    => [0 => ''],
            ],
            ['arquivos' => [0 => new UploadedFile($origem, $nomeOriginal, null, null, true)]],
        );

        $novos = array_values(array_diff($this->arquivosNoDiretorio(), $antes));
        foreach ($novos as $novo) {
            $this->arquivosCriados[] = $this->diretorio() . '/' . $novo;
        }

        return $novos;
    }

    /** @return list<ClienteDocumento> */
    private function documentosDoCliente(int $clienteId): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
        $em->clear();

        return $em->getRepository(ClienteDocumento::class)->findBy(['cliente' => $clienteId]);
    }

    private function diretorio(): string
    {
        return (string) static::getContainer()->getParameter('clientes_uploads_dir');
    }

    /** @return list<string> */
    private function arquivosNoDiretorio(): array
    {
        $diretorio = $this->diretorio();

        if (!is_dir($diretorio)) {
            return [];
        }

        return array_values(array_diff(scandir($diretorio) ?: [], ['.', '..']));
    }

    /**
     * Limpa a identity map para que o ParamConverter do controller execute SQL real (e passe pelo
     * TenantFilter), como numa requisição de produção.
     */
    private function limparIdentityMap(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
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

    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant CLI UPLOAD ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarGestor(Tenant $tenant): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('gestor_upload_' . uniqid() . '@test.com');
        $user->setFullName('Gestor ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        // Papel de sistema → gestor do tenant (passa em canAccessResource).
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Gestor ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }

    private function criarClientePF(Tenant $tenant): ClientePF
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $sufixo = substr(uniqid(), -6);

        $cliente = new ClientePF();
        $cliente->setNomeCompleto('ClienteUpload Z' . $sufixo);
        $cliente->setCpf(str_pad((string) random_int(1, 99_999_999), 11, '0', STR_PAD_LEFT));
        $cliente->setRg('RG' . $sufixo);
        $cliente->setRgOrgaoExpedidor('SSP/SP');
        $cliente->setEmail('cliente_' . uniqid() . '@test.com');
        $cliente->setCep('01310100');
        $cliente->setEndereco('Av. Paulista, 1000');
        $cliente->setCidade('São Paulo');
        $cliente->setEstado('SP');
        $cliente->setTenant($tenant);
        $em->persist($cliente);
        $em->flush();

        return $cliente;
    }
}
