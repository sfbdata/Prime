<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Service\RegraDosMasters;
use App\Tenant\Controller\EscritorioController;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * As rotas antigas de exclusão (`escritorio_excluir` e `app_tenant_delete`) recusam no servidor a
 * conta criada por um Master (spec perfis-1.2-contas C5 e Q4), com a mesma regra do painel
 * ({@see RegraDosMasters::recusaParaExcluir()}). Conta comum continua sendo excluída como antes
 * (coberto em EscritorioControllerTest e TenantRotasTenantIdControllerTest).
 */
#[CoversClass(EscritorioController::class)]
#[CoversClass(TenantController::class)]
final class ExclusaoDaContaDoMasterTest extends JusPrimeWebTestCase
{
    use Factories;

    #[TestDox('escritorio_excluir: nem o admin do escritório nem o próprio Master excluem a conta do Master')]
    public function testEscritorioExcluirRecusa(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfFixo();
        $master = UserFactory::createOne(['roles' => ['ROLE_SUPER_ADMIN'], 'isActive' => true])->_real();
        $conta  = $this->contaDoMaster($master);
        $admin  = UserFactory::createOne(['roles' => ['ROLE_USER'], 'isActive' => true])->_real();
        $this->vincularComoAdmin($admin, $conta);
        $this->vincularComoAdmin($master, $conta);

        foreach ([$admin, $master] as $quem) {
            $this->logarComTenant($client, $quem, $conta);
            $client->request('POST', '/escritorio/' . $conta->getId() . '/excluir', ['_token' => 'TOKEN_excluir_' . $conta->getId(), 'confirmar_nome' => $conta->getName()]);

            self::assertResponseRedirects('/tenant/' . $conta->getId());
            $client->followRedirect();
            self::assertStringContainsString(RegraDosMasters::RECUSA_EXCLUIR_CONTA_DO_MASTER, (string) $client->getResponse()->getContent());
        }

        $this->assertContaIntacta($conta);
    }

    #[TestDox('app_tenant_delete: o super-admin não exclui a conta do Master')]
    public function testTenantDeleteRecusa(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfFixo();
        $master = UserFactory::createOne(['roles' => ['ROLE_SUPER_ADMIN'], 'isActive' => true])->_real();
        $conta  = $this->contaDoMaster($master);

        $client->loginUser($master);
        $this->marcarTermosAceitos($client);
        $client->request('POST', '/tenant/' . $conta->getId(), ['_token' => 'TOKEN_delete' . $conta->getId()]);

        self::assertResponseRedirects('/tenant', 303);
        self::assertContains(RegraDosMasters::RECUSA_EXCLUIR_CONTA_DO_MASTER, $client->getRequest()->getSession()->getFlashBag()->peek('danger'));
        $this->assertContaIntacta($conta);
    }

    private function contaDoMaster(User $master): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $conta = (new Tenant())->setName('Conta do Master ' . uniqid())->setCriadoPor($master);
        $em->persist($conta);
        $em->flush();

        return $conta;
    }

    private function vincularComoAdmin(User $user, Tenant $conta): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $perfil = (new TenantRole())->setTenant($conta)->setName('Administrador do Escritório')->setIsSystem(true);
        $em->persist($perfil);
        $em->persist((new UserTenant($user, $conta))->setTenantRole($perfil));
        $em->flush();
    }

    private function assertContaIntacta(Tenant $conta): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $recarregada = $em->find(Tenant::class, $conta->getId());
        self::assertNotNull($recarregada);
        self::assertTrue($recarregada->isActive());
        self::assertNull($recarregada->getExcluidoEm());
    }

    private function instalarCsrfFixo(): void
    {
        static::getContainer()->set('security.csrf.token_storage', new class () implements ClearableTokenStorageInterface {
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
        });
    }
}
