<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Controller\TenantRoleController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Regressão do 500 "Field \"0\" has already been rendered" nas telas de papel (new/edit).
 *
 * O `tenant_role/_form.html.twig` renderizava o grupo `resources` só por um WHITELIST hardcoded
 * (cliente/pasta/processo × view/edit/delete). Qualquer permissão `resources.*` fora disso (ex.: as de
 * Cobrança — `resources.cobranca.gerenciar`/`carteira.gerenciar`/`movimentacao_financeira`, presentes no
 * `PermissionFixture`) ficava SEM render → `form.permissions` parcial → o `form_rest` (do `form_end`)
 * re-renderizava o widget inteiro e quebrava com 500. Como não havia teste cobrindo o RENDER dessas telas,
 * o bug chegou a produção quando o catálogo de prod ganhou as permissões de Cobrança.
 *
 * Este teste carrega `new` e `edit` (que usam o mesmo partial) e exige 200 + que a permissão de recurso
 * fora do whitelist apareça como checkbox (portanto concedível).
 */
#[CoversClass(TenantRoleController::class)]
final class TenantRoleFormRenderControllerTest extends JusPrimeWebTestCase
{
    #[TestDox('new e edit de papel renderizam (200) mesmo com permissões de recurso fora do whitelist; o checkbox aparece')]
    public function testFormsDePapelRenderizamComRecursoForaDoWhitelist(): void
    {
        $client = static::createClient();
        $this->garantirPermissaoRecursoForaDoWhitelist();
        $tenant = $this->criarTenant();
        $admin  = $this->criarAdmin($tenant);
        $this->logarComTenant($client, $admin, $tenant);

        $id = (int) $tenant->getId();
        $role = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(TenantRole::class)->findOneBy(['tenant' => $tenant]);
        self::assertNotNull($role);

        // /new e /edit compartilham o _form.html.twig que renderiza as permissões agrupadas.
        $client->request('GET', "/tenant/{$id}/roles/new");
        self::assertResponseIsSuccessful('criar papel deveria renderizar (permissão de recurso fora do whitelist)');

        $crawler = $client->request('GET', "/tenant/{$id}/roles/{$role->getId()}/edit");
        self::assertResponseIsSuccessful('editar papel deveria renderizar (permissão de recurso fora do whitelist)');

        // A permissão de recurso fora do whitelist (Cobrança) precisa aparecer como checkbox → concedível.
        self::assertGreaterThan(
            0,
            $crawler->filter('input[type=checkbox][value="resources.cobranca.gerenciar"]')->count(),
            'a permissão resources.cobranca.gerenciar deveria ser renderizada (bloco "Outros recursos")',
        );
    }

    /**
     * Garante que exista ao menos uma permissão de grupo `resources` FORA do whitelist hardcoded do
     * template (cliente/pasta/processo × view/edit/delete) — é o gatilho do bug. Deixa o teste
     * determinístico, independente do estado do `PermissionFixture` no banco de teste.
     */
    private function garantirPermissaoRecursoForaDoWhitelist(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        if ($em->getRepository(Permission::class)->findOneBy(['code' => 'resources.cobranca.gerenciar']) !== null) {
            return;
        }

        $permissao = new Permission();
        $permissao->setCode('resources.cobranca.gerenciar');
        $permissao->setDescription('Gerenciar casos de cobrança (operar obrigações, contatos, ações)');
        $permissao->setGroup('resources');
        $em->persist($permissao);
        $em->flush();
    }

    private function criarTenant(): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant ROLE FORM ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    /** Admin do escritório: vínculo ativo + TenantRole isSystem (canAdminister=true em qualquer módulo). */
    private function criarAdmin(Tenant $tenant): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('admin_roleform_' . uniqid() . '@test.com');
        $user->setFullName('Admin Role Form');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Admin ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }
}
