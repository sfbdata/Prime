<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Controller\TenantController;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Kanban\Entity\KanbanBoard;
use App\Repository\UserTenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use App\Tests\Functional\JusPrimeWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Substitui DemitirFuncionarioControllerTest — cobre a rota app_tenant_user_remover.
 * Os helpers criarTenant/criarAdmin/criarFuncionario são copiados de lá (padrão de CSRF
 * fake + login já validado). O caso do coração da decisão D7 (super-admin não passa por
 * cima da trava do último administrador) usa um helper próprio, criarAdminDoEscritorio,
 * porque criarAdmin() só marca ROLE_SUPER_ADMIN no User — não cria o vínculo com
 * TenantRole::isSystem=true que RemoverColaboradorDoEscritorioUseCase::ehUltimoAdmin()
 * exige para reconhecer alguém como administrador DO ESCRITÓRIO.
 */
#[CoversClass(TenantController::class)]
final class RemoverColaboradorControllerTest extends JusPrimeWebTestCase
{
    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant Remover ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarAdmin(Tenant $tenant): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('admin_remover_' . uniqid() . '@test.com');
        $user->setFullName('Admin Remover');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return $user;
    }

    private function criarFuncionario(Tenant $tenant): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('func_remover_' . uniqid() . '@test.com');
        $user->setFullName('Funcionário Teste');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return $user;
    }

    /**
     * Administrador DO ESCRITÓRIO de verdade: vínculo com TenantRole::isSystem=true, o que
     * UserTenantRepository::contarAdminsAtivos() e ehUltimoAdmin() exigem. Só existe nesta
     * classe porque a trava do último admin (D7) é nova — DemitirFuncionarioUseCase não tinha.
     */
    private function criarAdminDoEscritorio(Tenant $tenant): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Administrador Master');
        $role->setIsSystem(true);
        $em->persist($role);

        $user = new User();
        $user->setEmail('admin_escritorio_' . uniqid() . '@test.com');
        $user->setFullName('Administrador do Escritório');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($role);
        $em->persist($vinculo);
        $em->flush();

        return $user;
    }

    /**
     * O catálogo de permissões é global (PermissionFixture), então a linha pode já existir no
     * banco de teste — reaproveita em vez de duplicar o code.
     */
    private function permissaoGerenciarUsuarios(): Permission
    {
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $perm = $em->getRepository(Permission::class)->findOneBy(['code' => 'admin.users.manage']);

        if ($perm !== null) {
            return $perm;
        }

        $perm = new Permission();
        $perm->setCode('admin.users.manage');
        $perm->setDescription('Gerenciar funcionários (editar perfil, desativar)');
        $perm->setGroup('admin');
        $em->persist($perm);
        $em->flush();

        return $perm;
    }

    /**
     * O caminho REAL de produção: administrador do escritório SEM ROLE_SUPER_ADMIN, com vínculo
     * ativo e um perfil NÃO-sistema que carrega a permissão `admin.users.manage` de verdade. É
     * o único jeito de fazer o guard do controller passar por `canAdminister()` —
     * com super-admin ele curto-circuita em `isGlobalSuperAdmin()` e a permissão nunca é lida.
     */
    private function criarAdminComumComPermissao(Tenant $tenant): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Gestor de Pessoas ' . uniqid());
        $role->setIsSystem(false);
        $em->persist($role);

        $vinculoPermissao = new TenantRolePermission();
        $vinculoPermissao->setTenantRole($role);
        $vinculoPermissao->setPermission($this->permissaoGerenciarUsuarios());
        $em->persist($vinculoPermissao);
        $role->getTenantRolePermissions()->add($vinculoPermissao);

        $user = new User();
        $user->setEmail('admin_comum_' . uniqid() . '@test.com');
        $user->setFullName('Admin Comum');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($role);
        $em->persist($vinculo);
        $em->flush();

        return $user;
    }

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

    private function gerarCsrf(string $tokenId): string
    {
        return 'TOKEN_' . $tokenId;
    }

    #[TestDox('POST remover sem substituto redireciona com 302 e apaga o vínculo')]
    public function testRemoverSemSubstitutoRetorna302EApagaOVinculo(): void
    {
        $client      = static::createClient();
        $tenant      = $this->criarTenant();
        $admin       = $this->criarAdmin($tenant);
        $funcionario = $this->criarFuncionario($tenant);

        $this->instalarCsrfStorage();
        $client->loginUser($admin);
        $this->marcarTermosAceitos($client);
        $client->request('POST', "/tenant/{$tenant->getId()}/user/{$funcionario->getId()}/remover", [
            '_token' => $this->gerarCsrf('remover_' . $funcionario->getId()),
        ]);

        self::assertResponseRedirects("/tenant/{$tenant->getId()}/users");

        $em       = static::getContainer()->get(EntityManagerInterface::class);
        $vinculo  = $em->getRepository(UserTenant::class)->findOneBy([
            'user'   => $funcionario,
            'tenant' => $tenant,
        ]);
        self::assertNull($vinculo, 'o vínculo deveria ter sido apagado (hard delete)');

        // A conta continua existindo — a remoção é do vínculo, não da pessoa.
        $funcionarioAtualizado = $em->find(User::class, $funcionario->getId());
        self::assertNotNull($funcionarioAtualizado);
    }

    /**
     * O caminho principal de produção — e o único que exercita `canAdminister()`. Todos os
     * outros casos felizes desta classe logam com `criarAdmin()`, que marca ROLE_SUPER_ADMIN:
     * o guard do controller (`$isSuperAdmin || ($isOwnTenant && canAdminister(...))`) fica
     * satisfeito pelo primeiro termo e a permissão `admin.users.manage` nunca chega a ser
     * consultada. Sem este teste, trocar o código da permissão por um inexistente deixaria a
     * suíte inteira verde e o admin de escritório tomando 403 na tela.
     */
    #[TestDox('POST remover por administrador COMUM (sem super-admin) com admin.users.manage remove de verdade')]
    public function testAdminComumComPermissaoRemove(): void
    {
        $client      = static::createClient();
        $tenant      = $this->criarTenant();
        $adminComum  = $this->criarAdminComumComPermissao($tenant);
        $funcionario = $this->criarFuncionario($tenant);

        self::assertNotContains(
            'ROLE_SUPER_ADMIN',
            $adminComum->getRoles(),
            'o executor deste caso NÃO pode ser super-admin, senão o guard nem lê a permissão'
        );

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $adminComum, $tenant);
        $client->request('POST', "/tenant/{$tenant->getId()}/user/{$funcionario->getId()}/remover", [
            '_token' => $this->gerarCsrf('remover_' . $funcionario->getId()),
        ]);

        self::assertResponseRedirects("/tenant/{$tenant->getId()}/users");

        $em      = static::getContainer()->get(EntityManagerInterface::class);
        $vinculo = $em->getRepository(UserTenant::class)->findOneBy([
            'user'   => $funcionario,
            'tenant' => $tenant,
        ]);
        self::assertNull($vinculo, 'o admin do escritório com admin.users.manage tem de conseguir remover');
    }

    /**
     * Spec §6.3 (o registro explícito com o tenant da ROTA) e §6.4 (a trilha depende de
     * `actor_user_id` estar gravado) — conferidos contra a linha REAL do banco, não contra um
     * mock. No unit todos os `getId()` são nulos, então `actorUserId`, `actorEmail` e o
     * conteúdo de `changes` (inclusive `substituto_id`) não têm como ser afirmados lá.
     */
    #[TestDox('a remocao grava audit_log com tenant da rota, ator e payload — incluindo substituto_id')]
    public function testAuditoriaDaRemocaoGravadaNoBanco(): void
    {
        $client      = static::createClient();
        $tenant      = $this->criarTenant();
        $admin       = $this->criarAdmin($tenant);
        $funcionario = $this->criarFuncionario($tenant);
        $substituto  = $this->criarFuncionario($tenant);

        $this->instalarCsrfStorage();
        $client->loginUser($admin);
        $this->marcarTermosAceitos($client);
        $client->request('POST', "/tenant/{$tenant->getId()}/user/{$funcionario->getId()}/remover", [
            '_token'       => $this->gerarCsrf('remover_' . $funcionario->getId()),
            'substituto_id' => (string) $substituto->getId(),
        ]);

        self::assertResponseRedirects("/tenant/{$tenant->getId()}/users");

        $em = static::getContainer()->get(EntityManagerInterface::class);

        // O AuditLogSubscriber também grava um 'delete' de UserTenant (com o tenant da SESSÃO,
        // spec §6.3). O registro DESTA feature é o que carrega `colaborador_id` no payload.
        $registros = array_values(array_filter(
            $em->getRepository(AuditLog::class)->findBy([
                'entityClass' => UserTenant::class,
                'action'      => 'delete',
            ]),
            static fn (AuditLog $log): bool => array_key_exists('colaborador_id', (array) $log->getChanges())
        ));

        self::assertCount(1, $registros, 'a remoção tem de deixar exatamente um rastro explícito');
        $log = $registros[0];

        self::assertSame($tenant->getId(), $log->getTenantId(), 'o tenant do rastro tem de ser o da ROTA (spec §6.3)');
        self::assertSame($admin->getId(), $log->getActorUserId(), 'sem actor_user_id a trilha da §6.4 não tem quem filtrar');
        self::assertSame($admin->getEmail(), $log->getActorEmail());

        $changes = (array) $log->getChanges();
        self::assertSame($funcionario->getId(), $changes['colaborador_id']);
        self::assertSame($funcionario->getEmail(), $changes['colaborador_email']);
        self::assertSame($funcionario->getFullName(), $changes['colaborador_nome']);
        self::assertSame('painel', $changes['origem']);
        self::assertSame(
            $substituto->getId(),
            $changes['substituto_id'],
            'o substituto que herdou as responsabilidades tem de ficar no rastro'
        );
    }

    #[TestDox('POST remover com CSRF inválido retorna 403')]
    public function testTokenCsrfInvalidoRetorna403(): void
    {
        $client      = static::createClient();
        $tenant      = $this->criarTenant();
        $admin       = $this->criarAdmin($tenant);
        $funcionario = $this->criarFuncionario($tenant);

        $client->loginUser($admin);
        $this->marcarTermosAceitos($client);
        $client->request('POST', "/tenant/{$tenant->getId()}/user/{$funcionario->getId()}/remover", [
            '_token' => 'token_invalido',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('POST remover por usuário sem admin.users.manage retorna 403')]
    public function testNaoPermiteAcessoSemPermissaoRetorna403(): void
    {
        $client       = static::createClient();
        $tenant       = $this->criarTenant();
        $semPermissao = $this->criarFuncionario($tenant);
        $alvo         = $this->criarFuncionario($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $semPermissao, $tenant);

        $client->request('POST', "/tenant/{$tenant->getId()}/user/{$alvo->getId()}/remover", [
            '_token' => $this->gerarCsrf('remover_' . $alvo->getId()),
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('POST remover alvo com vínculo em outro escritório retorna 404 (B-route)')]
    public function testAlvoDeOutroEscritorioRetorna404(): void
    {
        $client   = static::createClient();
        $tenantA  = $this->criarTenant();
        $tenantB  = $this->criarTenant();
        $admin    = $this->criarAdmin($tenantA);
        $alvoDeB  = $this->criarFuncionario($tenantB);

        $this->instalarCsrfStorage();
        $client->loginUser($admin);
        $this->marcarTermosAceitos($client);

        $client->request('POST', "/tenant/{$tenantA->getId()}/user/{$alvoDeB->getId()}/remover", [
            '_token' => $this->gerarCsrf('remover_' . $alvoDeB->getId()),
        ]);

        self::assertResponseStatusCodeSame(404);

        // O vínculo de B não pode ter sido tocado.
        $em      = static::getContainer()->get(EntityManagerInterface::class);
        $vinculo = $em->getRepository(UserTenant::class)->findOneBy([
            'user'   => $alvoDeB,
            'tenant' => $tenantB,
        ]);
        self::assertNotNull($vinculo, 'o vínculo do outro escritório não deveria ter sido tocado');
    }

    #[TestDox('nem super-admin remove o ultimo administrador do escritorio (D7)')]
    public function testSuperAdminNaoRemoveOUltimoAdmin(): void
    {
        $client = static::createClient();
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = $this->criarTenant();

        // Único administrador do escritório — vínculo com TenantRole::isSystem=true.
        $unicoAdmin = $this->criarAdminDoEscritorio($tenant);

        // O executor é super-admin e NÃO tem vínculo com este escritório: o guard de
        // permissão do controller o deixa passar de qualquer forma (isSuperAdmin bypassa
        // canAdminister). A trava real precisa vir do UseCase, não do controller.
        $suporte = new User();
        $suporte->setEmail('suporte_' . uniqid() . '@test.com');
        $suporte->setFullName('Suporte');
        $suporte->setRoles(['ROLE_SUPER_ADMIN']);
        $suporte->setIsActive(true);
        $em->persist($suporte);
        $em->flush();

        $this->instalarCsrfStorage();
        $client->loginUser($suporte);
        $this->marcarTermosAceitos($client);

        $client->request('POST', "/tenant/{$tenant->getId()}/user/{$unicoAdmin->getId()}/remover", [
            '_token' => $this->gerarCsrf('remover_' . $unicoAdmin->getId()),
        ]);

        // O UseCase recusa (InvalidArgumentException) e o controller responde com flash de
        // erro + redirect — não é um 403/404, é a operação sendo negada e revertida.
        self::assertResponseRedirects("/tenant/{$tenant->getId()}/users");

        $em->clear();
        $vinculo = static::getContainer()->get(UserTenantRepository::class)
            ->findPorUserETenant(
                $em->find(User::class, $unicoAdmin->getId()),
                $em->find(Tenant::class, $tenant->getId())
            );

        self::assertNotNull($vinculo, 'o super-admin conseguiu remover o último administrador');
    }

    /**
     * ACHADO M2. A spec §3.3 manda o executor herdar os quadros do removido pela porta do
     * painel, supondo que executor = admin DO ESCRITÓRIO. Mas o guard do controller deixa
     * passar super-admin SEM vínculo nenhum com o tenant — e um `kanban_board.criado_por_id`
     * apontando para quem não é colaborador esconde o quadro do escritório inteiro, porque o
     * KanbanBoardRepository só lista para criador ou participante (spec §8). Decisão do
     * orquestrador: sem vínculo ativo, o executor não herda; cai no mesmo caminho da porta da
     * saída (admin ativo de vínculo mais antigo).
     */
    #[TestDox('super-admin SEM vinculo nao herda os quadros: eles vao para o admin do escritorio (M2)')]
    public function testSuperAdminSemVinculoNaoHerdaOsQuadros(): void
    {
        $client = static::createClient();
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = $this->criarTenant();

        $adminDoEscritorio = $this->criarAdminDoEscritorio($tenant);
        $criadorDoQuadro   = $this->criarFuncionario($tenant);

        $quadro = new KanbanBoard('Quadro ' . uniqid(), $tenant, $criadorDoQuadro);
        $em->persist($quadro);

        // Executor: super-admin de plataforma, SEM vínculo com este escritório.
        $suporte = new User();
        $suporte->setEmail('suporte_m2_' . uniqid() . '@test.com');
        $suporte->setFullName('Suporte Sem Vínculo');
        $suporte->setRoles(['ROLE_SUPER_ADMIN']);
        $suporte->setIsActive(true);
        $em->persist($suporte);
        $em->flush();

        $quadroId = (int) $quadro->getId();

        $this->instalarCsrfStorage();
        $client->loginUser($suporte);
        $this->marcarTermosAceitos($client);
        $client->request('POST', "/tenant/{$tenant->getId()}/user/{$criadorDoQuadro->getId()}/remover", [
            '_token' => $this->gerarCsrf('remover_' . $criadorDoQuadro->getId()),
        ]);

        self::assertResponseRedirects("/tenant/{$tenant->getId()}/users");

        $conn      = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $herdeiro  = (int) $conn->fetchOne('SELECT criado_por_id FROM kanban_board WHERE id = ?', [$quadroId]);

        self::assertSame(
            $adminDoEscritorio->getId(),
            $herdeiro,
            'o quadro foi parar com quem não é colaborador do escritório — ninguém de lá voltaria a enxergá-lo'
        );
        self::assertNotSame($suporte->getId(), $herdeiro);
    }

    #[TestDox('GET users nao lista quem acabou de ser removido')]
    public function testListaUsersNaoMostraQuemFoiRemovido(): void
    {
        $client      = static::createClient();
        $tenant      = $this->criarTenant();
        $admin       = $this->criarAdmin($tenant);
        $funcionario = $this->criarFuncionario($tenant);

        $this->instalarCsrfStorage();
        $client->loginUser($admin);
        $this->marcarTermosAceitos($client);
        $client->request('POST', "/tenant/{$tenant->getId()}/user/{$funcionario->getId()}/remover", [
            '_token' => $this->gerarCsrf('remover_' . $funcionario->getId()),
        ]);
        self::assertResponseRedirects("/tenant/{$tenant->getId()}/users");

        $client->request('GET', "/tenant/{$tenant->getId()}/users");

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(
            (string) $funcionario->getEmail(),
            (string) $client->getResponse()->getContent(),
            'a pessoa removida (vínculo apagado) não deveria aparecer na lista'
        );
    }

    /**
     * Este é o teste que prova a tarefa. O teste acima (quem acabou de ser removido) passaria
     * sozinho mesmo sem nenhuma mudança no controller/template: com o hard delete, a pessoa já
     * some da lista porque o vínculo não existe mais. Quem confirma que `listUsers` passou a
     * filtrar por `isActive = true` é um vínculo INATIVO LEGADO — que continua existindo na
     * tabela `user_tenant`, só com `is_active = false` — igual às 3 linhas que a spec (§6.5/§9)
     * registra como ainda presentes em produção hoje. Antes desta tarefa (§10), esse tipo de
     * linha aparecia na tabela principal com o selo verde "Ativo", porque a separação era feita
     * por `demitidoEm` (sempre nulo numa saída voluntária) e o badge lia `user.isActive` — o
     * flag global da conta, não o do vínculo.
     */
    #[TestDox('GET users nao lista vinculo inativo legado (is_active=false sem hard delete)')]
    public function testListaUsersNaoMostraVinculoInativoLegado(): void
    {
        $client = static::createClient();
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = $this->criarTenant();
        $admin  = $this->criarAdmin($tenant);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $legado = new User();
        $legado->setEmail('legado_' . uniqid() . '@test.com');
        $legado->setFullName('Vínculo Legado Inativo');
        $legado->setRoles(['ROLE_USER']);
        $legado->setIsActive(true);
        $legado->setPassword($hasher->hashPassword($legado, 'senha123'));
        $em->persist($legado);

        // UserTenant::sair() foi enterrado junto com demitir() — o vínculo inativo legado
        // (spec §6.5/§6.6) é simulado marcando isActive direto via reflection.
        $vinculo = new UserTenant($legado, $tenant);
        (new \ReflectionProperty(UserTenant::class, 'isActive'))->setValue($vinculo, false);
        $em->persist($vinculo);
        $em->flush();

        $this->instalarCsrfStorage();
        $client->loginUser($admin);
        $this->marcarTermosAceitos($client);

        $client->request('GET', "/tenant/{$tenant->getId()}/users");

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(
            (string) $legado->getEmail(),
            (string) $client->getResponse()->getContent(),
            'um vínculo com is_active=false (legado) não deveria aparecer na lista de colaboradores'
        );
    }
}
