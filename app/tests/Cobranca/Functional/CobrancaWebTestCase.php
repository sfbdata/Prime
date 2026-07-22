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
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
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
     * Usuário de um tenant com papel NÃO-system e SEM nenhuma permissão — nem o módulo `cobrancas`.
     * Prova o gate de MÓDULO das telas de leitura (`tenantComModulo` → `semAcesso`), que é anterior a
     * qualquer checagem de capacidade.
     *
     * @return array{0: User, 1: Tenant}
     */
    protected function criarUsuarioSemModulo(KernelBrowser $client): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant SemModulo ' . uniqid());
        $em->persist($tenant);

        // Papel vazio: nenhuma TenantRolePermission → o módulo `cobrancas` fica negado.
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Sem Modulo ' . uniqid());
        $role->setIsSystem(false);
        $em->persist($role);

        $user = new User();
        $user->setEmail('cobranca_sem_modulo_' . uniqid() . '@test.com');
        $user->setFullName('Usuario Sem Modulo');
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
     * Operador com papel NÃO-system que possui o módulo `cobrancas` (leitura) MAIS um conjunto
     * específico de capacidades — para provar que as capacidades são INDEPENDENTES entre si (ex.:
     * `movimentacao_financeira` sem `gerenciar`, ou o inverso). Só as capacidades passadas são
     * concedidas; qualquer outra continua negada.
     *
     * @param list<string> $capacidades códigos de permissão (ex.: `resources.cobranca.movimentacao_financeira`)
     *
     * @return array{0: User, 1: Tenant}
     */
    protected function criarOperadorComCapacidades(KernelBrowser $client, array $capacidades): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant CapEspecifica ' . uniqid());
        $em->persist($tenant);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Operador Capacidade ' . uniqid());
        $role->setIsSystem(false);
        $em->persist($role);

        // Módulo de leitura (para passar o gate de módulo) + cada capacidade pedida.
        foreach (array_merge(['modules.cobrancas.view'], $capacidades) as $code) {
            $perm = $em->getRepository(Permission::class)->findOneBy(['code' => $code]);
            if ($perm === null) {
                $perm = new Permission();
                $perm->setCode($code);
                $perm->setDescription($code);
                $perm->setGroup(str_contains($code, 'modules.') ? 'modules' : 'resources');
                $em->persist($perm);
                $em->flush();
            }

            $trp = new TenantRolePermission();
            $trp->setTenantRole($role);
            $trp->setPermission($perm);
            $em->persist($trp);
            $role->getTenantRolePermissions()->add($trp);
        }

        $user = new User();
        $user->setEmail('cobranca_cap_' . uniqid() . '@test.com');
        $user->setFullName('Operador Capacidade');
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
     * Semeia Carteira→Objeto→Caso (+ Pessoa cobrada) no tenant. Aceita overrides do Caso e,
     * opcionalmente, da Carteira.
     *
     * T1+T2 (cascata de encargos ao vivo sem snapshot, #9): NEM o `ResolvedorConfigEncargos` (T1) NEM
     * o `CalculadoraHonorarios` (T2, split do pagamento/gross-up) leem mais as colunas de config do
     * Caso (`taxaJurosMensalBp`/`taxaMultaBp`/`formaHonorarios`/`percentualHonorarios`/etc.) — a fonte
     * AO VIVO é sempre a Carteira (via Objeto). Testes que precisam de encargos NÃO-ZERO na tela (F4
     * "colunas separadas", split de encargos, gross-up do "Receber") devem usar `$overridesCarteira`
     * para essas chaves; `$overridesCaso` só vale para campos que ainda são do PRÓPRIO Caso
     * (`status`, etc.) — as colunas de config que sobram nele são sombra morta (T2).
     *
     * @param array<string, mixed> $overridesCaso
     * @param array<string, mixed> $overridesCarteira
     *
     * @return array{0: Carteira, 1: CasoCobranca}
     */
    protected function semearGrafo(Tenant $tenant, array $overridesCaso = [], array $overridesCarteira = []): array
    {
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant]);
        $carteira = CarteiraFactory::createOne(array_merge([
            'tenant' => $tenant,
            'cliente' => $cliente,
        ], $overridesCarteira));
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

    /**
     * Token CSRF por INTENÇÃO, direto do gerenciador — para endpoints cujo form saiu da UI (dormente
     * no backend, #9-T3) mas cuja rota continua ativa: sem markup na página, não há `<input>` para
     * `tokenDoFormulario` raspar. Precisa de UMA requisição já servida pelo `$client` (sessão iniciada)
     * antes de chamar — o token é empurrado/desempurrado na `RequestStack` só para o `SessionTokenStorage`
     * achar a sessão da request corrente; `framework.test: true` (ambiente de teste) mantém essa mesma
     * sessão viva entre requisições do MESMO client, sem depender de cookie roundtrip.
     */
    protected function tokenCsrf(KernelBrowser $client, string $intencao): string
    {
        $requestStack = static::getContainer()->get(RequestStack::class);
        $requestStack->push($client->getRequest());

        try {
            return (string) static::getContainer()->get(CsrfTokenManagerInterface::class)->getToken($intencao);
        } finally {
            $requestStack->pop();
        }
    }
}
