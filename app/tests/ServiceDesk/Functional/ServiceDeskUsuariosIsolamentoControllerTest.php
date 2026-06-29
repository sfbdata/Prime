<?php

declare(strict_types=1);

namespace App\Tests\ServiceDesk\Functional;

use App\Controller\ServiceDeskController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Entity\ServiceDesk\Chamado;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Form\ChamadoType;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * A1 — o diretório de usuários (dropdowns de técnico/responsável do ServiceDesk) vazava TODOS os
 * usuários de TODOS os escritórios: `User` não é TenantAware, então `findBy(['isActive'=>true])`
 * escapa do TenantFilter. Agora os dropdowns usam `findColaboradoresAtivosPorTenant` e a atribuição
 * valida o vínculo ATIVO no tenant DONO do chamado — id de outro escritório → 404, sem gravar
 * responsável nem notificar.
 */
#[CoversClass(ServiceDeskController::class)]
final class ServiceDeskUsuariosIsolamentoControllerTest extends JusPrimeWebTestCase
{
    private const NOME_COLAB_A = 'Fulano DoEscritorioA Unico';
    private const NOME_USER_B  = 'Beltrano DoEscritorioB Unico';

    #[TestDox('A1 leitura: o dashboard não lista técnicos de outro escritório no dropdown')]
    public function testDashboardNaoListaTecnicosDeOutroTenant(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $adminA  = $this->criarUsuario($tenantA, 'Admin Escritorio A', admin: true);
        $this->criarUsuario($tenantA, self::NOME_COLAB_A);
        $this->criarUsuario($tenantB, self::NOME_USER_B);

        $this->logarComTenant($client, $adminA, $tenantA);
        $client->request('GET', '/servicedesk');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString(self::NOME_COLAB_A, $body, 'o colaborador do próprio escritório deveria aparecer no dropdown de técnicos');
        self::assertStringNotContainsString(self::NOME_USER_B, $body, 'o dropdown de técnicos vazou um usuário de outro escritório');
    }

    #[TestDox('A1 leitura: a tela do chamado não lista técnicos de outro escritório no dropdown')]
    public function testShowNaoListaTecnicosDeOutroTenant(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $adminA  = $this->criarUsuario($tenantA, 'Admin Escritorio A', admin: true);
        $colabA  = $this->criarUsuario($tenantA, self::NOME_COLAB_A);
        $this->criarUsuario($tenantB, self::NOME_USER_B);
        // Solicitante = adminA, para que NOME_COLAB_A só possa aparecer via o dropdown de técnicos.
        $chamado = $this->criarChamado($adminA, $tenantA);

        $this->logarComTenant($client, $adminA, $tenantA);
        $client->request('GET', "/servicedesk/{$chamado->getId()}");

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString(self::NOME_COLAB_A, $body, 'o colaborador do próprio escritório deveria aparecer no dropdown de atribuição');
        self::assertStringNotContainsString(self::NOME_USER_B, $body, 'o dropdown de atribuição vazou um usuário de outro escritório');
        // (colabA fica sem uso direto como objeto, mas garante existência de um colaborador de A)
        unset($colabA);
    }

    #[TestDox('A1 escrita: atribuir a um usuário de outro escritório dá 404, sem gravar nem notificar')]
    public function testAtribuirRejeitaResponsavelDeOutroTenant(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $adminA  = $this->criarUsuario($tenantA, 'Admin Escritorio A', admin: true);
        $colabA  = $this->criarUsuario($tenantA, self::NOME_COLAB_A);
        $userB   = $this->criarUsuario($tenantB, self::NOME_USER_B);
        $chamado = $this->criarChamado($colabA, $tenantA);
        $chamadoId = (int) $chamado->getId();
        $userBId   = (int) $userB->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $adminA, $tenantA);
        $client->request('POST', "/servicedesk/{$chamadoId}/atribuir", ['responsavel_id' => $userBId, '_token' => 'TOKEN_servicedesk_atribuir_' . $chamadoId]);

        self::assertResponseStatusCodeSame(404, 'atribuir a um usuário de outro escritório deveria dar 404');

        $recarregado = $this->recarregarSemFiltro(Chamado::class, $chamadoId);
        self::assertNotNull($recarregado);
        self::assertNull($recarregado->getResponsavel(), 'o chamado de A não pode ganhar um responsável de B');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(0, $em->getRepository(Notificacao::class)->findBy(['usuario' => $userBId]), 'não pode notificar um usuário de outro escritório');
    }

    #[TestDox('A1 escrita: atribuir a um colaborador do próprio escritório funciona (controle positivo)')]
    public function testAtribuirAceitaResponsavelDoProprioTenant(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        $adminA  = $this->criarUsuario($tenantA, 'Admin Escritorio A', admin: true);
        $colabA  = $this->criarUsuario($tenantA, self::NOME_COLAB_A);
        $chamado = $this->criarChamado($adminA, $tenantA);
        $chamadoId = (int) $chamado->getId();
        $colabAId  = (int) $colabA->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $adminA, $tenantA);
        $client->request('POST', "/servicedesk/{$chamadoId}/atribuir", ['responsavel_id' => $colabAId, '_token' => 'TOKEN_servicedesk_atribuir_' . $chamadoId]);

        self::assertResponseRedirects();

        $recarregado = $this->recarregarSemFiltro(Chamado::class, $chamadoId);
        self::assertNotNull($recarregado);
        self::assertSame($colabAId, $recarregado->getResponsavel()?->getId(), 'o colaborador do próprio escritório deveria ter sido atribuído');
    }

    #[TestDox('A1 form: o EntityType de responsável (modo admin) só oferece colaboradores do tenant')]
    public function testFormResponsavelAdminEscopaPorTenant(): void
    {
        static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $this->criarUsuario($tenantA, self::NOME_COLAB_A);
        $this->criarUsuario($tenantB, self::NOME_USER_B);

        // O campo 'responsavel' só é construído com is_admin: true (tela admin / futura E4).
        $form = static::getContainer()->get('form.factory')
            ->create(ChamadoType::class, new Chamado(), ['is_admin' => true, 'tenant' => $tenantA]);
        $view = $form->get('responsavel')->createView();

        $labels = array_map(static fn ($choiceView) => $choiceView->label, $view->vars['choices']);
        self::assertContains(self::NOME_COLAB_A, $labels, 'o colaborador do próprio tenant deveria estar disponível no form');
        self::assertNotContains(self::NOME_USER_B, $labels, 'o form de chamado ofereceu um usuário de outro escritório');
    }

    // ----------------------------------------------------------------- helpers

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

    /**
     * @template T of object
     * @param class-string<T> $classe
     * @return T|null
     */
    private function recarregarSemFiltro(string $classe, int $id): ?object
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
        $em->clear();

        return $em->find($classe, $id);
    }

    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant SD ' . uniqid());
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
        $user->setEmail('u_' . uniqid() . '@test.com');
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

    private function criarChamado(User $solicitante, Tenant $tenant): Chamado
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $chamado = new Chamado();
        $chamado->setTitulo('Chamado teste ' . uniqid());
        $chamado->setDescricao('Descrição de teste');
        $chamado->setCategoria(Chamado::CATEGORIA_SOFTWARE);
        $chamado->setPrioridade(Chamado::PRIORIDADE_MEDIA);
        $chamado->setStatus(Chamado::STATUS_ABERTO);
        $chamado->setSolicitante($solicitante);
        $chamado->setTenant($tenant);
        $em->persist($chamado);
        $em->flush();

        return $chamado;
    }
}
