<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tenant\Controller\TenantUserProfileController;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Auditoria 1.08: nas rotas de usuário do escritório, a guarda de quem executa (403) vem antes da guarda do alvo
 * (vínculo ativo com o escritório da URL, 404). Na ordem antiga, quem é de outro escritório lia o 404/403 e
 * mapeava quais ids de usuário estão em qual escritório. Agora a resposta é a mesma para alvo com e sem vínculo.
 */
#[CoversClass(TenantController::class)]
#[CoversClass(TenantUserProfileController::class)]
final class OrdemDasGuardasDoUsuarioDoEscritorioTest extends JusPrimeWebTestCase
{
    /** @return iterable<string, array{string, string}> método e caminho depois de /tenant/{A}/user/{U} */
    public static function rotas(): iterable
    {
        yield 'editar nome' => ['POST', '/edit-name'];
        yield 'lançar batida' => ['POST', '/ponto/add'];
        yield 'editar batida (GET)' => ['GET', '/ponto/1/edit'];
        yield 'editar batida (POST)' => ['POST', '/ponto/1/edit'];
        yield 'excluir batida' => ['POST', '/ponto/1/delete'];
        yield 'abonar justificativa' => ['POST', '/justificativa/1/aprovar'];
        yield 'rejeitar justificativa' => ['POST', '/justificativa/1/rejeitar'];
        yield 'abonar todas' => ['POST', '/justificativa/batch/lote/aprovar-todos'];
        yield 'reverter justificativa' => ['POST', '/justificativa/1/reverter'];
        yield 'nova justificativa' => ['POST', '/justificativa/nova'];
        yield 'anexo da justificativa' => ['GET', '/justificativa/1/anexo'];
        yield 'dados pessoais' => ['POST', '/dados-pessoais'];
    }

    #[DataProvider('rotas')]
    #[TestDox('Usuário de outro escritório recebe 403 em $metodo $sufixo, com o alvo dentro ou fora do escritório')]
    public function testMesmaRespostaParaAlvoComESemVinculo(string $metodo, string $sufixo): void
    {
        $client = static::createClient();
        $a = $this->tenant();
        $b = $this->tenant();
        $deFora = $this->admin($b);
        $membroDeA = $this->usuario($a);
        $membroDeB = $this->usuario($b);

        $this->logarComTenant($client, $deFora, $b);

        $client->request($metodo, "/tenant/{$a->getId()}/user/{$membroDeA->getId()}{$sufixo}");
        self::assertResponseStatusCodeSame(403, 'alvo com vínculo em A');

        $client->request($metodo, "/tenant/{$a->getId()}/user/{$membroDeB->getId()}{$sufixo}");
        self::assertResponseStatusCodeSame(403, 'alvo sem vínculo em A: a mesma resposta');
    }

    #[DataProvider('rotas')]
    #[TestDox('Admin do escritório com admin.users.manage segue recebendo 404 em $metodo $sufixo para alvo sem vínculo')]
    public function testAdminDoEscritorioRecebe404ParaAlvoDeFora(string $metodo, string $sufixo): void
    {
        $client = static::createClient();
        $a = $this->tenant();
        $b = $this->tenant();
        $adminDeA = $this->admin($a);
        $membroDeB = $this->usuario($b);

        $this->logarComTenant($client, $adminDeA, $a);
        $client->request($metodo, "/tenant/{$a->getId()}/user/{$membroDeB->getId()}{$sufixo}");

        self::assertResponseStatusCodeSame(404);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function tenant(): Tenant
    {
        $tenant = (new Tenant())->setName('Ordem das guardas ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    /** Admin com TenantRole isSystem: canAdminister vale no escritório dele (e só nele). */
    private function admin(Tenant $tenant): User
    {
        $user = $this->pessoa('admin_');
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Admin ' . uniqid());
        $role->setIsSystem(true);
        $this->em()->persist($role);
        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($role);
        $this->em()->persist($vinculo);
        $this->em()->flush();

        return $user;
    }

    private function usuario(Tenant $tenant): User
    {
        $user = $this->pessoa('alvo_');
        $this->em()->persist(new UserTenant($user, $tenant));
        $this->em()->flush();

        return $user;
    }

    private function pessoa(string $prefixo): User
    {
        $user = new User();
        $user->setEmail($prefixo . uniqid() . '@test.com');
        $user->setFullName('Pessoa ' . $prefixo);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em()->persist($user);

        return $user;
    }
}
