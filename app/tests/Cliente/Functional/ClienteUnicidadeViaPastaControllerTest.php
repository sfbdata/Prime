<?php

declare(strict_types=1);

namespace App\Tests\Cliente\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * C3 — o SEGUNDO caminho de criação de Cliente (`PastaController::novoCliente`, rota
 * `pasta_cliente_novo`) não usa UniqueEntity: faz checagem manual via findOneBy. Após remover o
 * unique global do banco, essa checagem precisa escopar por tenant explicitamente — senão vaza
 * existência cross-tenant e bloqueia indevidamente. Prova via HTTP: mesmo cpf/cnpj é permitido
 * em pastas de escritórios diferentes e bloqueado dentro do mesmo escritório.
 */
#[CoversClass(PastaController::class)]
final class ClienteUnicidadeViaPastaControllerTest extends JusPrimeWebTestCase
{
    private int $seq = 0;

    #[TestDox('Mesmo CPF em pastas de tenants diferentes é permitido pela rota da pasta')]
    public function testMesmoCpfEmTenantsDiferentesEhPermitido(): void
    {
        $client = static::createClient();
        $client->disableReboot(); // mantém o container (e o storage CSRF fake) entre as requests
        $this->instalarCsrfStorage();

        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $gestorA = $this->criarGestor($tenantA);
        $gestorB = $this->criarGestor($tenantB);
        $pastaA  = $this->criarPasta($tenantA);
        $pastaB  = $this->criarPasta($tenantB);
        $cpf = '11122233344';

        $this->logarComTenant($client, $gestorA, $tenantA);
        $this->postNovoClientePF($client, $pastaA, $cpf);
        self::assertResponseIsSuccessful('cadastro em A deveria suceder');
        self::assertStringContainsString('sucesso', (string) $client->getResponse()->getContent());

        $this->logarComTenant($client, $gestorB, $tenantB);
        $this->postNovoClientePF($client, $pastaB, $cpf); // MESMO cpf, outro tenant
        self::assertResponseIsSuccessful('mesmo cpf em outro tenant deveria suceder (não vazar/bloquear)');
        self::assertStringContainsString('sucesso', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Mesmo CPF no mesmo tenant é bloqueado pela rota da pasta')]
    public function testMesmoCpfNoMesmoTenantEhBloqueado(): void
    {
        $client = static::createClient();
        $client->disableReboot(); // mantém o container (e o storage CSRF fake) entre as requests
        $this->instalarCsrfStorage();

        $tenantA = $this->criarTenant();
        $gestorA = $this->criarGestor($tenantA);
        $pastaA  = $this->criarPasta($tenantA);
        $pastaA2 = $this->criarPasta($tenantA);
        $cpf = '55566677788';

        $this->logarComTenant($client, $gestorA, $tenantA);
        $this->postNovoClientePF($client, $pastaA, $cpf);
        self::assertResponseIsSuccessful();

        $this->postNovoClientePF($client, $pastaA2, $cpf); // mesmo cpf, mesmo tenant
        self::assertResponseStatusCodeSame(422, 'cpf duplicado no mesmo tenant deveria ser bloqueado');
        self::assertStringContainsString('erro', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Mesmo CNPJ em pastas de tenants diferentes é permitido pela rota da pasta')]
    public function testMesmoCnpjEmTenantsDiferentesEhPermitido(): void
    {
        $client = static::createClient();
        $client->disableReboot(); // mantém o container (e o storage CSRF fake) entre as requests
        $this->instalarCsrfStorage();

        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $gestorA = $this->criarGestor($tenantA);
        $gestorB = $this->criarGestor($tenantB);
        $pastaA  = $this->criarPasta($tenantA);
        $pastaB  = $this->criarPasta($tenantB);
        $cnpj = '11222333000144';

        $this->logarComTenant($client, $gestorA, $tenantA);
        $this->postNovoClientePJ($client, $pastaA, $cnpj);
        self::assertResponseIsSuccessful('cadastro PJ em A deveria suceder');

        $this->logarComTenant($client, $gestorB, $tenantB);
        $this->postNovoClientePJ($client, $pastaB, $cnpj); // MESMO cnpj, outro tenant
        self::assertResponseIsSuccessful('mesmo cnpj em outro tenant deveria suceder');
        self::assertStringContainsString('sucesso', (string) $client->getResponse()->getContent());
    }

    // ----------------------------------------------------------------- helpers

    private function postNovoClientePF(KernelBrowser $client, Pasta $pasta, string $cpf): void
    {
        $client->xmlHttpRequest('POST', "/pasta/{$pasta->getId()}/cliente/novo", [
            '_token'           => $this->csrf($pasta),
            'tipo'             => 'pf',
            'nomeCompleto'     => 'Cliente PF ' . (++$this->seq),
            'cpf'              => $cpf,
            'rg'               => '12345' . $this->seq,
            'rgOrgaoExpedidor' => 'SSP/SP',
            'email'            => 'pf_' . uniqid() . '@test.com',
            'cep'              => '01310100',
            'endereco'         => 'Av. Paulista, 1000',
            'cidade'           => 'São Paulo',
            'estado'           => 'SP',
        ]);
    }

    private function postNovoClientePJ(KernelBrowser $client, Pasta $pasta, string $cnpj): void
    {
        $client->xmlHttpRequest('POST', "/pasta/{$pasta->getId()}/cliente/novo", [
            '_token'             => $this->csrf($pasta),
            'tipo'               => 'pj',
            'razaoSocial'        => 'Empresa PJ ' . (++$this->seq),
            'cnpj'               => $cnpj,
            'enderecSede'        => 'Av. Teste, ' . $this->seq,
            'representanteLegal' => 'Representante ' . $this->seq,
            'representanteCpf'   => str_pad((string) ($this->seq + 700), 11, '0', STR_PAD_LEFT),
            'representanteRg'    => 'RG' . $this->seq,
            'representanteCargo' => 'Diretor',
            'email'              => 'pj_' . uniqid() . '@test.com',
            'cep'                => '01310100',
            'endereco'           => 'Av. Paulista, 1000',
            'cidade'             => 'São Paulo',
            'estado'             => 'SP',
        ]);
    }

    private function csrf(Pasta $pasta): string
    {
        return 'TOKEN_pasta_cliente_novo_' . $pasta->getId();
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

    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant UNIC-PASTA ' . uniqid());
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
        $user->setEmail('gestor_' . uniqid() . '@test.com');
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

    private function criarPasta(Tenant $tenant): Pasta
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $pasta = new Pasta();
        $pasta->setNup('NUP-UNIC-' . (++$this->seq) . '-' . substr(uniqid(), -6));
        $pasta->setTenant($tenant);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }
}
