<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Expediente\Controller\ExpedienteController;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PrioridadePasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cobre os filtros da listagem de pastas do Expediente (acervo geral, endpoint AJAX):
 * busca livre, prioridade e período por data de abertura. Confirma que o controller
 * lê os parâmetros da query string e devolve apenas o subconjunto correto.
 */
#[CoversClass(ExpedienteController::class)]
final class ExpedienteFiltroPastasControllerTest extends JusPrimeWebTestCase
{
    #[TestDox('acervo geral: busca livre retorna só a pasta cujo nome da ação casa com o termo')]
    public function testBuscaLivreFiltraPorNomeAcao(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin  = $this->criarUsuario($tenant, 'Admin Filtro', admin: true);

        $sufixo = strtoupper(uniqid());
        $match  = $this->criarPasta($tenant, 'BUSCA-MATCH-' . $sufixo, nomeAcao: 'AcaoAlvo ' . $sufixo);
        $outra  = $this->criarPasta($tenant, 'BUSCA-OUTRA-' . $sufixo, nomeAcao: 'Coisa Diferente');

        $this->logarComTenant($client, $admin, $tenant);
        $client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral?busca=' . rawurlencode('acaoalvo ' . $sufixo));

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString($match->getNup(), $body);
        self::assertStringNotContainsString($outra->getNup(), $body);
    }

    #[TestDox('acervo geral: filtro de prioridade retorna só as pastas urgentes')]
    public function testFiltraPorPrioridade(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin  = $this->criarUsuario($tenant, 'Admin Prioridade', admin: true);

        $sufixo  = strtoupper(uniqid());
        $urgente = $this->criarPasta($tenant, 'PRIO-URG-' . $sufixo, prioridade: PrioridadePasta::Urgente);
        $normal  = $this->criarPasta($tenant, 'PRIO-NOR-' . $sufixo, prioridade: PrioridadePasta::Normal);

        $this->logarComTenant($client, $admin, $tenant);
        $client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral?prioridade=urgente');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString($urgente->getNup(), $body);
        self::assertStringNotContainsString($normal->getNup(), $body);
    }

    #[TestDox('acervo geral: filtro de período retorna só as pastas abertas no intervalo')]
    public function testFiltraPorPeriodo(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin  = $this->criarUsuario($tenant, 'Admin Periodo', admin: true);

        $sufixo = strtoupper(uniqid());
        $dentro = $this->criarPasta($tenant, 'PER-IN-' . $sufixo, dataAbertura: new \DateTimeImmutable('2024-03-10 09:00:00'));
        $fora   = $this->criarPasta($tenant, 'PER-OUT-' . $sufixo, dataAbertura: new \DateTimeImmutable('2024-05-20 09:00:00'));

        $this->logarComTenant($client, $admin, $tenant);
        $client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral?data_de=2024-03-01&data_ate=2024-03-31');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString($dentro->getNup(), $body);
        self::assertStringNotContainsString($fora->getNup(), $body);
    }

    // ----------------------------------------------------------------- helpers

    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant FILTRO ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarUsuario(Tenant $tenant, string $nome, bool $admin = false): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('f_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $userTenant = new UserTenant($user, $tenant);
        if ($admin) {
            $role = new TenantRole();
            $role->setTenant($tenant);
            $role->setName('Administrador ' . uniqid());
            $role->setIsSystem(true);
            $em->persist($role);
            $userTenant->setTenantRole($role);
        }
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }

    private function criarPasta(
        Tenant $tenant,
        string $nup,
        ?string $nomeAcao = null,
        ?PrioridadePasta $prioridade = null,
        ?\DateTimeImmutable $dataAbertura = null,
    ): Pasta {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $pasta = new Pasta();
        $pasta->setNup($nup);
        $pasta->setTenant($tenant);
        if ($nomeAcao !== null) {
            $pasta->setNomeAcao($nomeAcao);
        }
        if ($prioridade !== null) {
            $pasta->setPrioridade($prioridade);
        }
        if ($dataAbertura !== null) {
            $pasta->setDataAbertura($dataAbertura);
        }
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }
}
