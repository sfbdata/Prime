<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Cobranca\CasoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\PessoaFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Base dos testes funcionais das MUTAÇÕES de Cobrança (Onda 8B). Fornece setup de usuário/tenant
 * com e sem capacidade, semeadura de grafo Carteira→Objeto→Caso, e extração do token CSRF do form
 * renderizado. Grafo sempre no MESMO tenant do usuário logado (invariável 1/23), salvo cross-tenant
 * explícito.
 */
abstract class CobrancaWebTestCase extends JusPrimeWebTestCase
{
    use Factories;

    /**
     * Admin com papel isSystem → passa o gate de módulo E todas as capacidades (bypass do checker).
     *
     * @return array{0: User, 1: Tenant}
     */
    protected function criarAdminLogado(KernelBrowser $client): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Cobrança ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('cobranca_admin_' . uniqid() . '@test.com');
        $user->setFullName('Gestor Cobrança');
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

        $this->logarComTenant($client, $user, $tenant);

        return [$user, $tenant];
    }

    /**
     * Operador com papel NÃO-system que possui só o módulo `cobrancas` (leitura) e nenhuma das
     * capacidades de escrita — para provar que a mutação nega mesmo com o módulo liberado.
     *
     * @return array{0: User, 1: Tenant}
     */
    protected function criarOperadorSemCapacidade(KernelBrowser $client): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant SemCap ' . uniqid());
        $em->persist($tenant);

        $perm = $em->getRepository(Permission::class)->findOneBy(['code' => 'modules.cobrancas.view']);
        if ($perm === null) {
            $perm = new Permission();
            $perm->setCode('modules.cobrancas.view');
            $perm->setDescription('Acesso ao módulo Gestão de Cobranças');
            $perm->setGroup('modules');
            $em->persist($perm);
            $em->flush();
        }

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Operador Leitura ' . uniqid());
        $role->setIsSystem(false);
        $em->persist($role);

        $trp = new TenantRolePermission();
        $trp->setTenantRole($role);
        $trp->setPermission($perm);
        $em->persist($trp);
        $role->getTenantRolePermissions()->add($trp);

        $user = new User();
        $user->setEmail('cobranca_operador_' . uniqid() . '@test.com');
        $user->setFullName('Operador Sem Capacidade');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);

        return [$user, $tenant];
    }

    /**
     * Semeia Carteira→Objeto→Caso (+ Pessoa cobrada) no tenant. Aceita overrides do Caso.
     *
     * @param array<string, mixed> $overridesCaso
     *
     * @return array{0: Carteira, 1: CasoCobranca}
     */
    protected function semearGrafo(Tenant $tenant, array $overridesCaso = []): array
    {
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant]);
        $carteira = CarteiraFactory::createOne(['tenant' => $tenant, 'cliente' => $cliente]);
        $objeto = ObjetoCobrancaFactory::createOne(['tenant' => $tenant, 'carteira' => $carteira]);
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant]);
        $caso = CasoCobrancaFactory::createOne(array_merge([
            'tenant' => $tenant,
            'objeto' => $objeto,
            'pessoaCobradaAtual' => $pessoa,
        ], $overridesCaso));

        return [$carteira->_real(), $caso->_real()];
    }

    protected function tenantAvulso(): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Outro Tenant ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    /**
     * Token CSRF do form renderizado (campo `<nome>[_token]`), para POSTs diretos às rotas.
     */
    protected function tokenDoFormulario(Crawler $crawler, string $nomeForm): string
    {
        return (string) $crawler->filter('input[name="' . $nomeForm . '[_token]"]')->first()->attr('value');
    }
}
