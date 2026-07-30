<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Ponto\Entity\LancamentoHorasPagas;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Aba "Horas pagas" da ficha do funcionário (`app_tenant_user_edit_role`). É a única tela em que o
 * motivo do lançamento aparece — sem teste aqui, um erro de nome de variável no Twig só apareceria
 * no smoke manual do dono.
 */
#[CoversClass(TenantController::class)]
final class HorasPagasTabTenantControllerTest extends JusPrimeWebTestCase
{
    #[TestDox('lançamento negativo aparece com sinal "-", cor de perigo e o motivo na aba')]
    public function testExibeLancamentoNegativoComSinalECor(): void
    {
        $client      = static::createClient();
        $tenant      = $this->criarTenant();
        $admin       = $this->criarAdmin($tenant);
        $colaborador = $this->criarUsuario($tenant);
        $this->criarLancamento($tenant, $colaborador, $admin, -630, 'Desconto combinado com o colaborador');

        $this->logarComTenant($client, $admin, $tenant);
        $client->request('GET', "/tenant/{$tenant->getId()}/user/{$colaborador->getId()}/edit-role?tab=horas-pagas");

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('text-danger', $body, 'valor negativo tem de vir com a cor de perigo');
        self::assertStringContainsString('-10h30m', $body, 'o sinal e o total em h/m têm de bater com os minutos gravados');
        self::assertStringContainsString('Desconto combinado com o colaborador', $body, 'o motivo tem de aparecer na aba do admin');
        self::assertStringContainsString($admin->getFullName(), $body, 'quem lançou tem de aparecer como autor');
    }

    #[TestDox('lançamento positivo aparece com sinal "+" e cor de sucesso')]
    public function testExibeLancamentoPositivoComSinalECor(): void
    {
        $client      = static::createClient();
        $tenant      = $this->criarTenant();
        $admin       = $this->criarAdmin($tenant);
        $colaborador = $this->criarUsuario($tenant);
        $this->criarLancamento($tenant, $colaborador, $admin, 480, 'Bonificação por plantão extra');

        $this->logarComTenant($client, $admin, $tenant);
        $client->request('GET', "/tenant/{$tenant->getId()}/user/{$colaborador->getId()}/edit-role?tab=horas-pagas");

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('text-success', $body, 'valor positivo tem de vir com a cor de sucesso');
        self::assertStringContainsString('+8h00m', $body, 'o sinal e o total em h/m têm de bater com os minutos gravados');
        self::assertStringContainsString('Bonificação por plantão extra', $body);
    }

    #[TestDox('sem nenhum lançamento, a aba mostra o estado vazio')]
    public function testEstadoVazioSemLancamentos(): void
    {
        $client      = static::createClient();
        $tenant      = $this->criarTenant();
        $admin       = $this->criarAdmin($tenant);
        $colaborador = $this->criarUsuario($tenant);

        $this->logarComTenant($client, $admin, $tenant);
        $client->request('GET', "/tenant/{$tenant->getId()}/user/{$colaborador->getId()}/edit-role?tab=horas-pagas");

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Nenhum lançamento de horas pagas para este colaborador.', $body);
    }

    // ----------------------------------------------------------------- helpers

    private function criarTenant(): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant HORAS PAGAS TAB ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarAdmin(Tenant $tenant): User
    {
        $container = static::getContainer();
        $em     = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('admin_' . uniqid() . '@test.com');
        $user->setFullName('Admin Horas Pagas Tab');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Administrador ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }

    private function criarUsuario(Tenant $tenant): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('alvo_' . uniqid() . '@test.com');
        $user->setFullName('Alvo Horas Pagas Tab');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return $user;
    }

    private function criarLancamento(Tenant $tenant, User $colaborador, User $autor, int $minutos, string $motivo): LancamentoHorasPagas
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $referencia = new \DateTimeImmutable('first day of last month');

        $lancamento = new LancamentoHorasPagas();
        $lancamento->setTenant($tenant);
        $lancamento->setUser($colaborador);
        $lancamento->setAno((int) $referencia->format('Y'));
        $lancamento->setMes((int) $referencia->format('n'));
        $lancamento->setMinutos($minutos);
        $lancamento->setMotivo($motivo);
        $lancamento->setCriadoPor($autor);
        $lancamento->setCriadoEm(new \DateTimeImmutable());
        $em->persist($lancamento);
        $em->flush();

        return $lancamento;
    }
}
