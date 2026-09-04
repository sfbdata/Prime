<?php

declare(strict_types=1);

namespace App\Tests\Cliente\Functional;

use App\Cliente\Controller\ClienteController;
use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClienteDocumento;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Isolamento multi-tenant do ClienteController via HTTP. Usa gestores isSystem (que
 * bypassam o PermissionChecker) de propósito: prova que é o TenantFilter na camada de
 * dados — não a permissão — que fecha o vazamento. O dono enxerga o próprio cliente;
 * o gestor de outro tenant recebe 404 ao carregar por id (find/ParamConverter).
 *
 * Cada teste chama em->clear() após criar os fixtures: como o controller usa find()
 * por id (que consulta a identity map antes do SQL), sem limpar a identity map o
 * registro recém-criado seria devolvido sem passar pelo filtro — em produção cada
 * request começa com a identity map vazia e o filtro armado, então isto reproduz o
 * comportamento real.
 */
#[CoversClass(ClienteController::class)]
final class ClienteIsolamentoControllerTest extends JusPrimeWebTestCase
{
    private int $seq = 0;

    #[TestDox('Dono (mesmo tenant) acessa o próprio cliente; gestor de outro tenant recebe 404')]
    public function testShowIsolaPorTenant(): void
    {
        $client = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $gestorA = $this->criarGestor($tenantA, 'gestorA_' . uniqid() . '@test.com');
        $gestorB = $this->criarGestor($tenantB, 'gestorB_' . uniqid() . '@test.com');
        $clienteB = $this->criarClientePF($tenantB);
        $id = (int) $clienteB->getId();
        $this->limparIdentityMap();

        // Controle: o dono (tenant B) vê o próprio cliente.
        $this->logarComTenant($client, $gestorB, $tenantB);
        $client->request('GET', "/clientes/{$id}");
        self::assertResponseIsSuccessful();

        // Cross-tenant: gestor de A não acha o cliente de B (404 no find()).
        $this->logarComTenant($client, $gestorA, $tenantA);
        $client->request('GET', "/clientes/{$id}");
        self::assertResponseStatusCodeSame(404, 'show não pode revelar cliente de outro tenant');
    }

    #[TestDox('Editar/deletar cliente de outro tenant retorna 404')]
    public function testEditarEDeletarIsolamPorTenant(): void
    {
        $client = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $gestorA = $this->criarGestor($tenantA, 'gestorA_' . uniqid() . '@test.com');
        $clienteB = $this->criarClientePF($tenantB);
        $id = (int) $clienteB->getId();
        $this->limparIdentityMap();

        $this->logarComTenant($client, $gestorA, $tenantA);

        $client->request('GET', "/clientes/{$id}/editar");
        self::assertResponseStatusCodeSame(404, 'editar não pode revelar cliente de outro tenant');

        $client->request('POST', "/clientes/{$id}/deletar");
        self::assertResponseStatusCodeSame(404, 'deletar não pode tocar cliente de outro tenant');

        // Integridade: o cliente de B continua existindo (desliga o filtro p/ ler).
        $em = static::getContainer()->get(EntityManagerInterface::class);
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
        $em->clear();
        self::assertNotNull($em->find(Cliente::class, $id));
    }

    #[TestDox('Documento de cliente de outro tenant retorna 404 (visualizar/download)')]
    public function testDocumentoIsolaPorTenant(): void
    {
        $client = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $gestorA = $this->criarGestor($tenantA, 'gestorA_' . uniqid() . '@test.com');
        $clienteB = $this->criarClientePF($tenantB);
        $docB = $this->criarDocumento($clienteB);
        $docId = (int) $docB->getId();
        $this->limparIdentityMap();

        $this->logarComTenant($client, $gestorA, $tenantA);

        $client->request('GET', "/clientes/documento/{$docId}/visualizar");
        self::assertResponseStatusCodeSame(404, 'visualizar não pode revelar documento de outro tenant');

        $client->request('GET', "/clientes/documento/{$docId}/download");
        self::assertResponseStatusCodeSame(404, 'download não pode revelar documento de outro tenant');

        // editar/deletar carregam o documento pelo mesmo ParamConverter: o filtro o torna
        // inexistente para o tenant A, então o 404 acontece antes do corpo/CSRF.
        $client->request('POST', "/clientes/documento/{$docId}/editar");
        self::assertResponseStatusCodeSame(404, 'editar documento não pode tocar outro tenant');

        $client->request('POST', "/clientes/documento/{$docId}/deletar");
        self::assertResponseStatusCodeSame(404, 'deletar documento não pode tocar outro tenant');
    }

    #[TestDox('Cliente PJ de outro tenant também retorna 404 (show)')]
    public function testShowIsolaPorTenantPJ(): void
    {
        $client = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $gestorA = $this->criarGestor($tenantA, 'gestorA_' . uniqid() . '@test.com');
        $clienteB = $this->criarClientePJ($tenantB);
        $id = (int) $clienteB->getId();
        $this->limparIdentityMap();

        $this->logarComTenant($client, $gestorA, $tenantA);
        $client->request('GET', "/clientes/{$id}");
        self::assertResponseStatusCodeSame(404, 'show não pode revelar cliente PJ de outro tenant');
    }

    // ----------------------------------------------------------------- helpers

    /**
     * Limpa a identity map do EM compartilhado para que o find() do controller execute
     * SQL real (e portanto passe pelo TenantFilter), em vez de devolver a entidade
     * recém-criada nos fixtures. As entidades retornadas pelos helpers ficam destacadas,
     * mas seguem válidas para getId()/loginUser() (o firewall recarrega o usuário por id).
     */
    private function limparIdentityMap(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant CLI ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarGestor(Tenant $tenant, string $email): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setFullName('Gestor ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

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
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $n  = ++$this->seq;

        $cliente = new ClientePF();
        $cliente->setNomeCompleto('ClientePF' . $n . 'Z' . substr(uniqid(), -6));
        $cliente->setCpf(str_pad((string) $n, 11, '0', STR_PAD_LEFT));
        $cliente->setRg('123456' . $n);
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

    private function criarClientePJ(Tenant $tenant): ClientePJ
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $n  = ++$this->seq;

        $cliente = new ClientePJ();
        $cliente->setRazaoSocial('EmpresaPJ' . $n . 'Z' . substr(uniqid(), -6));
        $cliente->setCnpj(str_pad((string) $n, 14, '0', STR_PAD_LEFT));
        $cliente->setEnderecSede('Av. Teste, ' . $n);
        $cliente->setRepresentanteLegal('Representante ' . $n);
        $cliente->setRepresentanteCpf(str_pad((string) ($n + 700), 11, '0', STR_PAD_LEFT));
        $cliente->setRepresentanteRg('RG' . $n);
        $cliente->setRepresentanteCargo('Diretor');
        $cliente->setEmail('pj_' . uniqid() . '@test.com');
        $cliente->setCep('01310100');
        $cliente->setEndereco('Av. Paulista, 1000');
        $cliente->setCidade('São Paulo');
        $cliente->setEstado('SP');
        $cliente->setTenant($tenant);
        $em->persist($cliente);
        $em->flush();

        return $cliente;
    }

    private function criarDocumento(Cliente $cliente): ClienteDocumento
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $doc = new ClienteDocumento();
        $doc->setCliente($cliente);
        $doc->setTenant($cliente->getTenant());
        $doc->setTitulo('Documento ' . uniqid());
        $doc->setCategoria(ClienteDocumento::CATEGORIA_IDENTIFICACAO);
        $doc->setCaminhoArquivo('arquivo_' . uniqid() . '.pdf');
        $doc->setNomeOriginal('rg.pdf');
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(1024);
        $em->persist($doc);
        $em->flush();

        return $doc;
    }
}
