<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Controller\TenantRoleController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Fatia 4 da spec `docs/specs/acesso-restrito-e-compartilhar.md` (P1 a P12): o cartão do acesso restrito no
 * formulário de perfil, o que o servidor grava ao salvar, o selo e a descrição na lista e o rótulo no cadastro
 * do usuário. Travado = perfil restrito e código fora de `PermissoesDoAcessoRestrito::LIBERADAS`.
 */
#[CoversClass(TenantRoleController::class)]
final class AcessoRestritoNaTelaDePerfisTest extends JusPrimeWebTestCase
{
    private const LIBERADA = 'resources.pasta.view';
    private const ADMINISTRATIVA = 'admin.roles.manage';

    #[TestDox('Salvar perfil novo com o acesso restrito grava o rótulo e só as permissões liberadas')]
    public function testNovoPerfilRestritoGravaRotuloESoAsLiberadas(): void
    {
        [$client, $tenant] = $this->entrarComoAdmin();
        $id = (int) $tenant->getId();

        $crawler = $client->request('GET', "/tenant/{$id}/roles/new");
        self::assertResponseIsSuccessful();
        $client->request('POST', "/tenant/{$id}/roles/new", ['tenant_role' => [
            'name'           => 'Parceiros ' . uniqid(),
            'acessoRestrito' => '1',
            'rotulo'         => 'Parceiro',
            'permissions'    => [self::LIBERADA, self::ADMINISTRATIVA],
            '_token'         => $crawler->filter('input[name="tenant_role[_token]"]')->attr('value'),
        ]]);
        self::assertResponseRedirects();

        $perfil = $this->ultimoPerfilSecundario($tenant);
        self::assertTrue($perfil->temAcessoRestrito());
        self::assertSame('Parceiro', $perfil->getRotulo());
        self::assertSame([self::LIBERADA], $this->codigos($perfil));
    }

    #[TestDox('Desligar o acesso restrito libera o perfil e grava as permissões marcadas como vieram')]
    public function testDesligarLiberaOPerfil(): void
    {
        [$client, $tenant] = $this->entrarComoAdmin();
        $perfil = $this->criarPerfilRestrito($tenant);
        $url = "/tenant/{$tenant->getId()}/roles/{$perfil->getId()}/edit";

        $crawler = $client->request('GET', $url);
        $client->request('POST', $url, ['tenant_role' => [
            'name'        => $perfil->getName(),
            'rotulo'      => 'Prospectador',
            'permissions' => [self::LIBERADA, self::ADMINISTRATIVA],
            '_token'      => $crawler->filter('input[name="tenant_role[_token]"]')->attr('value'),
        ]]);
        self::assertResponseRedirects();

        $perfil = $this->recarregar($perfil);
        self::assertFalse($perfil->temAcessoRestrito());
        self::assertSame([self::ADMINISTRATIVA, self::LIBERADA], $this->codigos($perfil));
    }

    #[TestDox('Editar o perfil restrito mostra o cartão ligado, o rótulo escolhido e os itens fora da liberação travados')]
    public function testEdicaoDoRestritoMostraCartaoLigadoEItensTravados(): void
    {
        [$client, $tenant] = $this->entrarComoAdmin();
        $perfil = $this->criarPerfilRestrito($tenant);

        $crawler = $client->request('GET', "/tenant/{$tenant->getId()}/roles/{$perfil->getId()}/edit");
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('form.pf-form-perfil.is-restrito > .pf-rs.is-ligado'));
        self::assertNotNull($crawler->filter('input[name="tenant_role[acessoRestrito]"]')->attr('checked'));
        self::assertSame('Parceiro', $crawler->filter('select[name="tenant_role[rotulo]"] option[selected]')->attr('value'));
        self::assertStringContainsString('ACESSO RESTRITO ÀS PASTAS SOB RESPONSABILIDADE', $crawler->filter('.pf-rs-cab > b')->text());
        self::assertCount(8, $crawler->filter('.pf-rs-regras > .pf-rs-regra'));
        self::assertCount(0, $crawler->filter('.pf-rs select[name*="simular"], .pf-rs .bi-eye'), 'Simular ficou fora (Q1)');

        $administrativa = $crawler->filter(sprintf('input[value="%s"]', self::ADMINISTRATIVA));
        self::assertNotNull($administrativa->attr('disabled'));
        self::assertNull($administrativa->attr('checked'));
        self::assertCount(1, $crawler->filter(sprintf('label.pf-item.is-travado-rs > input[value="%s"]', self::ADMINISTRATIVA)));
        self::assertNull($crawler->filter(sprintf('input[value="%s"]', self::LIBERADA))->attr('disabled'));
        self::assertGreaterThan(0, $crawler->filter('.pf-cartao.is-travado-rs')->count(), 'o grupo de Administração fica todo travado');
        $faixas = implode(' | ', $crawler->filter('.pf-cartao > .pf-escopo')->each(static fn ($faixa) => $faixa->text()));
        self::assertStringContainsString('Vale só para as pastas sob responsabilidade do usuário (clientes dessas pastas)', $faixas);
    }

    #[TestDox('Perfil comum: o cartão aparece desligado e nenhum item fica travado')]
    public function testPerfilComumNaoMudaNada(): void
    {
        [$client, $tenant] = $this->entrarComoAdmin();
        $id = (int) $tenant->getId();

        $crawler = $client->request('GET', "/tenant/{$id}/roles/new");
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form.pf-form-perfil > .pf-rs'));
        self::assertCount(0, $crawler->filter('.pf-rs.is-ligado, form.is-restrito, .pf-item.is-travado-rs, .pf-cartao.is-travado-rs'));
        self::assertCount(0, $crawler->filter('input[data-pf-permissao][disabled]'));
    }

    #[TestDox('A lista mostra o selo e a descrição do restrito; o perfil comum fica sem selo')]
    public function testListaMostraSeloEDescricaoDoRestrito(): void
    {
        [$client, $tenant] = $this->entrarComoAdmin();
        $restrito = $this->criarPerfilRestrito($tenant);

        $crawler = $client->request('GET', "/tenant/{$tenant->getId()}/roles");
        self::assertResponseIsSuccessful();

        $selos = $crawler->filter('[data-pf-selo-restrito]');
        self::assertCount(1, $selos, 'só o perfil restrito tem selo');
        self::assertStringContainsString('ACESSO RESTRITO ÀS PASTAS SOB RESPONSABILIDADE', $selos->text());
        self::assertSame('Só vê as pastas em que é responsável', $selos->attr('title'));
        self::assertStringContainsString((string) $restrito->getName(), $crawler->filter('body')->text());
        self::assertStringContainsString('Acesso restrito às pastas sob responsabilidade', $crawler->filter('body')->text());
    }

    #[TestDox('O cadastro do usuário mostra o rótulo do perfil restrito em destaque')]
    public function testCadastroDoUsuarioMostraORotulo(): void
    {
        [$client, $tenant] = $this->entrarComoAdmin();
        $perfil = $this->criarPerfilRestrito($tenant);
        $colaborador = $this->criarUsuario($tenant, $perfil, 'parceiro');

        $crawler = $client->request('GET', "/tenant/{$tenant->getId()}/users");
        self::assertResponseIsSuccessful();
        self::assertSame('PARCEIRO', trim($crawler->filter('[data-rotulo-restrito]')->text()));

        $crawler = $client->request('GET', "/tenant/{$tenant->getId()}/user/{$colaborador->getId()}/edit-role");
        self::assertResponseIsSuccessful();
        self::assertSame('PARCEIRO', trim($crawler->filter('[data-rotulo-restrito]')->text()));
    }

    /** @return array{KernelBrowser, Tenant} */
    private function entrarComoAdmin(): array
    {
        $client = static::createClient();
        $this->garantirPermissoes();
        $em = $this->em();
        $tenant = new Tenant();
        $tenant->setName('Tenant RESTRITO PERFIS ' . uniqid());
        $em->persist($tenant);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Admin ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);
        $admin = $this->criarUsuario($tenant, $role, 'admin');
        $this->logarComTenant($client, $admin, $tenant);

        return [$client, $tenant];
    }

    private function criarUsuario(Tenant $tenant, TenantRole $role, string $prefixo): User
    {
        $em = $this->em();
        $user = new User();
        $user->setEmail($prefixo . '_restrito_' . uniqid() . '@test.com');
        $user->setFullName('Usuário ' . $prefixo);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $em->persist($user);

        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($role);
        $em->persist($vinculo);
        $em->flush();

        return $user;
    }

    private function criarPerfilRestrito(Tenant $tenant): TenantRole
    {
        $em = $this->em();
        $perfil = new TenantRole();
        $perfil->setTenant($tenant);
        $perfil->setName('Parceiros ' . uniqid());
        $perfil->restringirAsPastasSobResponsabilidade('Parceiro');
        $em->persist($perfil);
        $vinculo = new TenantRolePermission();
        $vinculo->setTenantRole($perfil);
        $vinculo->setPermission($this->permissao(self::LIBERADA));
        $em->persist($vinculo);
        $em->flush();

        return $perfil;
    }

    private function garantirPermissoes(): void
    {
        foreach ([self::LIBERADA => 'resources', self::ADMINISTRATIVA => 'admin'] as $codigo => $grupo) {
            if ($this->em()->getRepository(Permission::class)->findOneBy(['code' => $codigo]) !== null) {
                continue;
            }
            $permissao = new Permission();
            $permissao->setCode($codigo);
            $permissao->setDescription($codigo);
            $permissao->setGroup($grupo);
            $this->em()->persist($permissao);
        }
        $this->em()->flush();
    }

    private function permissao(string $codigo): Permission
    {
        $permissao = $this->em()->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
        self::assertNotNull($permissao);

        return $permissao;
    }

    private function ultimoPerfilSecundario(Tenant $tenant): TenantRole
    {
        $perfil = $this->em()->getRepository(TenantRole::class)
            ->findOneBy(['tenant' => $tenant, 'isSystem' => false], ['id' => 'DESC']);
        self::assertNotNull($perfil);

        return $this->recarregar($perfil);
    }

    private function recarregar(TenantRole $perfil): TenantRole
    {
        $em = $this->em();
        $em->clear();
        $recarregado = $em->getRepository(TenantRole::class)->find($perfil->getId());
        self::assertNotNull($recarregado);

        return $recarregado;
    }

    /** @return list<string> */
    private function codigos(TenantRole $perfil): array
    {
        $linhas = $this->em()->createQueryBuilder()
            ->select('p.code')->from(TenantRolePermission::class, 'trp')->join('trp.permission', 'p')
            ->andWhere('trp.tenantRole = :perfil')->setParameter('perfil', $perfil)
            ->orderBy('p.code')->getQuery()->getScalarResult();

        return array_column($linhas, 'code');
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
