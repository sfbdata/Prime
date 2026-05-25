<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(PastaRepository::class)]
final class PastaRepositoryIsolamentoTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PastaRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = static::getContainer()->get(PastaRepository::class);
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant ISO ' . uniqid());
        $this->em->persist($tenant);

        return $tenant;
    }

    private function criarPasta(Tenant $tenant, string $prefixo = 'ISO'): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup($prefixo . '-' . uniqid());
        $pasta->setTenant($tenant);
        $this->em->persist($pasta);

        return $pasta;
    }

    private function criarUser(): User
    {
        $user = new User();
        $user->setEmail('iso_' . uniqid() . '@test.com');
        $user->setFullName('User ISO Test');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('dummy_hash');
        $this->em->persist($user);

        return $user;
    }

    private function criarClientePF(string $nomeCompleto = 'Cliente ISO Test'): ClientePF
    {
        $cpf = substr(str_replace('.', '', uniqid('', true)), 0, 14);
        $cliente = new ClientePF();
        $cliente->setEmail('iso_' . uniqid() . '@test.com');
        $cliente->setCep('01310-100');
        $cliente->setEndereco('Av. Paulista, 1000');
        $cliente->setCidade('São Paulo');
        $cliente->setEstado('SP');
        $cliente->setCpf($cpf);
        $cliente->setRg('00.000.000-0');
        $cliente->setRgOrgaoExpedidor('SSP');
        $cliente->setNomeCompleto($nomeCompleto);
        $this->em->persist($cliente);

        return $cliente;
    }

    private function criarClientePJ(string $razaoSocial = 'Empresa ISO Test Ltda'): ClientePJ
    {
        $cnpj = substr(str_replace('.', '', uniqid('', true)), 0, 14);
        $cliente = new ClientePJ();
        $cliente->setEmail('iso_pj_' . uniqid() . '@test.com');
        $cliente->setCep('01310-100');
        $cliente->setEndereco('Av. Paulista, 2000');
        $cliente->setCidade('São Paulo');
        $cliente->setEstado('SP');
        $cliente->setCnpj($cnpj);
        $cliente->setRazaoSocial($razaoSocial);
        $cliente->setEnderecSede('Av. Paulista, 2000, São Paulo');
        $cliente->setRepresentanteLegal('Representante ISO');
        $cliente->setRepresentanteCpf('00000000000000');
        $cliente->setRepresentanteRg('00.000.000-0');
        $cliente->setRepresentanteCargo('Diretor');
        $this->em->persist($cliente);

        return $cliente;
    }

    #[TestDox('findByFilters retorna pastas do tenant A e não vaza pastas do tenant B')]
    public function testFindByFiltersIsolaTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $pastaA  = $this->criarPasta($tenantA, 'ISO-A');
        $pastaB  = $this->criarPasta($tenantB, 'ISO-B');
        $this->em->flush();

        $resultado = $this->repo->findByFilters([], $tenantA);
        $nups      = array_map(fn(Pasta $p) => $p->getNup(), $resultado);

        self::assertContains($pastaA->getNup(), $nups);
        self::assertNotContains($pastaB->getNup(), $nups);
    }

    #[TestDox('findAllNups retorna NUPs do tenant A e não vaza NUPs do tenant B')]
    public function testFindAllNupsIsolaTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $pastaA  = $this->criarPasta($tenantA, 'NUPS-A');
        $pastaB  = $this->criarPasta($tenantB, 'NUPS-B');
        $this->em->flush();

        $nups = $this->repo->findAllNups($tenantA);

        self::assertContains($pastaA->getNup(), $nups);
        self::assertNotContains($pastaB->getNup(), $nups);
    }

    #[TestDox('findAtivasPorResponsavel retorna pastas do tenant A e não vaza pastas do tenant B')]
    public function testFindAtivasPorResponsavelIsolaTenant(): void
    {
        $tenantA  = $this->criarTenant();
        $tenantB  = $this->criarTenant();
        $usuario  = $this->criarUser();
        $pastaA   = $this->criarPasta($tenantA, 'RESP-A');
        $pastaA->setResponsavel($usuario);
        $pastaB   = $this->criarPasta($tenantB, 'RESP-B');
        $pastaB->setResponsavel($usuario);
        $this->em->flush();

        $resultado = $this->repo->findAtivasPorResponsavel($usuario, $tenantA);
        $nups      = array_map(fn(Pasta $p) => $p->getNup(), $resultado);

        self::assertContains($pastaA->getNup(), $nups);
        self::assertNotContains($pastaB->getNup(), $nups);
    }

    #[TestDox('findByFilters filtra pasta por ClientePF vinculado (nomeCompleto LIKE)')]
    public function testFindByFiltersFiltraClientePF(): void
    {
        $tenant  = $this->criarTenant();
        $sufixo  = uniqid();
        $cliente = $this->criarClientePF('Filtro PF ' . $sufixo);
        $pasta   = $this->criarPasta($tenant, 'FILT-PF');
        $pasta->addCliente($cliente);
        $this->em->flush();

        $resultado = $this->repo->findByFilters(
            ['cliente' => mb_strtoupper('Filtro PF ' . $sufixo)],
            $tenant
        );
        $nups      = array_map(fn(Pasta $p) => $p->getNup(), $resultado);

        self::assertContains($pasta->getNup(), $nups);
    }

    #[TestDox('findByFilters filtra pasta por ClientePJ vinculado (razaoSocial LIKE)')]
    public function testFindByFiltersFiltraClientePJ(): void
    {
        $tenant  = $this->criarTenant();
        $sufixo  = uniqid();
        $cliente = $this->criarClientePJ('Empresa Filtro PJ ' . $sufixo);
        $pasta   = $this->criarPasta($tenant, 'FILT-PJ');
        $pasta->addCliente($cliente);
        $this->em->flush();

        $resultado = $this->repo->findByFilters(
            ['cliente' => mb_strtoupper('Filtro PJ ' . $sufixo)],
            $tenant
        );
        $nups      = array_map(fn(Pasta $p) => $p->getNup(), $resultado);

        self::assertContains($pasta->getNup(), $nups);
    }

    #[TestDox('findByCliente retorna pastas do tenant A e não vaza pastas do tenant B')]
    public function testFindByClienteIsolaTenant(): void
    {
        $tenantA  = $this->criarTenant();
        $tenantB  = $this->criarTenant();
        $cliente  = $this->criarClientePF();
        $pastaA   = $this->criarPasta($tenantA, 'CLI-A');
        $pastaA->addCliente($cliente);
        $pastaB   = $this->criarPasta($tenantB, 'CLI-B');
        $pastaB->addCliente($cliente);
        $this->em->flush();

        $resultado = $this->repo->findByCliente($cliente, $tenantA);
        $nups      = array_map(fn(Pasta $p) => $p->getNup(), $resultado);

        self::assertContains($pastaA->getNup(), $nups);
        self::assertNotContains($pastaB->getNup(), $nups);
    }
}
