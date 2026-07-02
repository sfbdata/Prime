<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PrioridadePasta;
use App\Processo\Entity\Processo;
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

    private function criarClientePF(Tenant $tenant, string $nomeCompleto = 'Cliente ISO Test'): ClientePF
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
        $cliente->setTenant($tenant);
        $this->em->persist($cliente);

        return $cliente;
    }

    private function criarClientePJ(Tenant $tenant, string $razaoSocial = 'Empresa ISO Test Ltda'): ClientePJ
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
        $cliente->setTenant($tenant);
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
        $cliente = $this->criarClientePF($tenant, 'Filtro PF ' . $sufixo);
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
        $cliente = $this->criarClientePJ($tenant, 'Empresa Filtro PJ ' . $sufixo);
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

    #[TestDox('findByFilters filtra ClientePJ de forma case-insensitive (maiúsculas→minúsculas)')]
    public function testFindByFiltersClientePJCaseInsensitive(): void
    {
        $tenant  = $this->criarTenant();
        $sufixo  = uniqid();
        $cliente = $this->criarClientePJ($tenant, mb_strtoupper('Empresa Case PJ ' . $sufixo));
        $pasta   = $this->criarPasta($tenant, 'CASE-PJ');
        $pasta->addCliente($cliente);
        $this->em->flush();

        $resultado = $this->repo->findByFilters(
            ['cliente' => mb_strtolower('Empresa Case PJ ' . $sufixo)],
            $tenant,
        );
        $nups = array_map(fn(Pasta $p) => $p->getNup(), $resultado);

        self::assertContains($pasta->getNup(), $nups);
    }

    #[TestDox('findByCliente retorna pastas do tenant A e não vaza pastas do tenant B')]
    public function testFindByClienteIsolaTenant(): void
    {
        $tenantA  = $this->criarTenant();
        $tenantB  = $this->criarTenant();
        $cliente  = $this->criarClientePF($tenantA);
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

    private function vincularProcessoPrincipal(
        Pasta $pasta,
        Tenant $tenant,
        string $numeroProcesso = '',
        string $classeProcessual = '',
    ): Processo {
        $processo = new Processo();
        $processo->setNumeroProcesso($numeroProcesso);
        $processo->setClasseProcessual($classeProcessual);
        $processo->setTenant($tenant);
        $this->em->persist($processo);

        $vinculo = $pasta->vincularProcesso($processo);
        $this->em->persist($vinculo);

        return $processo;
    }

    /** @return string[] */
    private function nupsDeBusca(string $busca, Tenant $tenant): array
    {
        $resultado = $this->repo->findByFilters(['busca' => $busca], $tenant);

        return array_map(fn(Pasta $p) => $p->getNup(), $resultado);
    }

    #[TestDox('busca livre encontra pasta pelo NUP (case-insensitive)')]
    public function testBuscaLivrePorNup(): void
    {
        $tenant = $this->criarTenant();
        $pasta  = $this->criarPasta($tenant, 'BUSCANUP');
        $this->em->flush();

        self::assertContains($pasta->getNup(), $this->nupsDeBusca('buscanup', $tenant));
    }

    #[TestDox('busca livre encontra pasta por ClientePF vinculado (nomeCompleto, case-insensitive)')]
    public function testBuscaLivrePorClientePF(): void
    {
        $tenant  = $this->criarTenant();
        $sufixo  = uniqid();
        $cliente = $this->criarClientePF($tenant, 'Busca PF ' . $sufixo);
        $pasta   = $this->criarPasta($tenant, 'BUSCA-PF');
        $pasta->addCliente($cliente);
        $this->em->flush();

        self::assertContains($pasta->getNup(), $this->nupsDeBusca(mb_strtoupper('Busca PF ' . $sufixo), $tenant));
    }

    #[TestDox('busca livre encontra pasta por ClientePJ vinculado (razaoSocial, case-insensitive)')]
    public function testBuscaLivrePorClientePJ(): void
    {
        $tenant  = $this->criarTenant();
        $sufixo  = uniqid();
        $cliente = $this->criarClientePJ($tenant, mb_strtoupper('Empresa Busca PJ ' . $sufixo));
        $pasta   = $this->criarPasta($tenant, 'BUSCA-PJ');
        $pasta->addCliente($cliente);
        $this->em->flush();

        self::assertContains($pasta->getNup(), $this->nupsDeBusca(mb_strtolower('Empresa Busca PJ ' . $sufixo), $tenant));
    }

    #[TestDox('busca livre encontra pasta pelo nome da ação')]
    public function testBuscaLivrePorNomeAcao(): void
    {
        $tenant = $this->criarTenant();
        $sufixo = uniqid();
        $pasta  = $this->criarPasta($tenant, 'BUSCA-ACAO');
        $pasta->setNomeAcao('Ação de Cobrança ' . $sufixo);
        $this->em->flush();

        self::assertContains($pasta->getNup(), $this->nupsDeBusca('cobrança ' . $sufixo, $tenant));
    }

    #[TestDox('busca livre encontra pasta pelo número do processo principal vinculado')]
    public function testBuscaLivrePorNumeroProcesso(): void
    {
        $tenant = $this->criarTenant();
        $sufixo = substr(preg_replace('/\D/', '', uniqid('', true)) ?? '', 0, 7);
        $pasta  = $this->criarPasta($tenant, 'BUSCA-PROC');
        $this->vincularProcessoPrincipal($pasta, $tenant, $sufixo . '-88.2026.8.26.0100', 'Mandado de Segurança');
        $this->em->flush();

        self::assertContains($pasta->getNup(), $this->nupsDeBusca($sufixo, $tenant));
    }

    #[TestDox('busca livre encontra pasta pela classe processual do processo principal')]
    public function testBuscaLivrePorClasseProcessual(): void
    {
        $tenant = $this->criarTenant();
        $sufixo = uniqid();
        $pasta  = $this->criarPasta($tenant, 'BUSCA-CLASSE');
        $this->vincularProcessoPrincipal($pasta, $tenant, '0001-88.2026.8.26.0100', 'Classe Busca ' . $sufixo);
        $this->em->flush();

        self::assertContains($pasta->getNup(), $this->nupsDeBusca(mb_strtolower('Classe Busca ' . $sufixo), $tenant));
    }

    #[TestDox('busca livre não vaza pastas de outro tenant (cross-tenant)')]
    public function testBuscaLivreIsolaTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $termo   = 'ISOLA ' . uniqid();

        $pastaA = $this->criarPasta($tenantA, 'BUSCA-ISO-A');
        $pastaA->setNomeAcao($termo);
        $pastaB = $this->criarPasta($tenantB, 'BUSCA-ISO-B');
        $pastaB->setNomeAcao($termo);
        $this->em->flush();

        $nups = $this->nupsDeBusca($termo, $tenantA);

        self::assertContains($pastaA->getNup(), $nups);
        self::assertNotContains($pastaB->getNup(), $nups);
    }

    #[TestDox('busca livre por cliente não vaza pasta de outro tenant com cliente homônimo (cross-tenant no JOIN)')]
    public function testBuscaLivrePorClienteIsolaTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $nome    = 'Homonimo Cross ' . uniqid();

        $clienteA = $this->criarClientePF($tenantA, $nome);
        $pastaA   = $this->criarPasta($tenantA, 'XCLI-A');
        $pastaA->addCliente($clienteA);

        $clienteB = $this->criarClientePF($tenantB, $nome);
        $pastaB   = $this->criarPasta($tenantB, 'XCLI-B');
        $pastaB->addCliente($clienteB);
        $this->em->flush();

        $nups = $this->nupsDeBusca(mb_strtolower($nome), $tenantA);

        self::assertContains($pastaA->getNup(), $nups);
        self::assertNotContains($pastaB->getNup(), $nups, 'a busca por cliente vazou pasta de outro escritório via JOIN de ClientePF');
    }

    #[TestDox('filtro de prioridade retorna só as pastas daquela prioridade')]
    public function testFiltraPorPrioridade(): void
    {
        $tenant   = $this->criarTenant();
        $urgente  = $this->criarPasta($tenant, 'PRIO-URG');
        $urgente->setPrioridade(PrioridadePasta::Urgente);
        $normal   = $this->criarPasta($tenant, 'PRIO-NOR');
        $normal->setPrioridade(PrioridadePasta::Normal);
        $this->em->flush();

        $resultado = $this->repo->findByFilters(['prioridade' => 'urgente'], $tenant);
        $nups      = array_map(fn(Pasta $p) => $p->getNup(), $resultado);

        self::assertContains($urgente->getNup(), $nups);
        self::assertNotContains($normal->getNup(), $nups);
    }

    #[TestDox('prioridade inválida é ignorada (filtro não aplicado)')]
    public function testPrioridadeInvalidaEIgnorada(): void
    {
        $tenant = $this->criarTenant();
        $pasta  = $this->criarPasta($tenant, 'PRIO-INV');
        $pasta->setPrioridade(PrioridadePasta::Normal);
        $this->em->flush();

        $resultado = $this->repo->findByFilters(['prioridade' => 'inexistente'], $tenant);
        $nups      = array_map(fn(Pasta $p) => $p->getNup(), $resultado);

        self::assertContains($pasta->getNup(), $nups);
    }

    #[TestDox('filtro de período (data de abertura) inclui as bordas e exclui fora do intervalo')]
    public function testFiltraPorPeriodoDataAbertura(): void
    {
        $tenant = $this->criarTenant();

        $dentro = $this->criarPasta($tenant, 'PER-DENTRO');
        $dentro->setDataAbertura(new \DateTimeImmutable('2025-06-15 10:00:00'));

        $borda  = $this->criarPasta($tenant, 'PER-BORDA');
        $borda->setDataAbertura(new \DateTimeImmutable('2025-06-30 23:30:00'));

        $fora   = $this->criarPasta($tenant, 'PER-FORA');
        $fora->setDataAbertura(new \DateTimeImmutable('2025-07-05 09:00:00'));

        $this->em->flush();

        $resultado = $this->repo->findByFilters(
            ['data_de' => '2025-06-01', 'data_ate' => '2025-06-30'],
            $tenant,
        );
        $nups = array_map(fn(Pasta $p) => $p->getNup(), $resultado);

        self::assertContains($dentro->getNup(), $nups);
        self::assertContains($borda->getNup(), $nups, 'a borda final deve incluir o dia inteiro (23:59:59)');
        self::assertNotContains($fora->getNup(), $nups);
    }
}
