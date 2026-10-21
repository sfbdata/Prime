<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Controller\TarefaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\DesligaTenantFilterNaRequisicao;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(TarefaController::class)]
final class CriarTarefaControllerTest extends JusPrimeWebTestCase
{
    use DesligaTenantFilterNaRequisicao;

    private function criarUsuario(Tenant $tenant, string $email): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setFullName('Usuário ' . uniqid());
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

    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant Criar ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    /**
     * Cria a pasta onde a meta será criada. O criador fica gravado para o cenário de C6-SEC-03:
     * o guarda antigo liberava a pasta pelo vínculo do criador, não pela posse do escritório.
     */
    private function criarPasta(Tenant $tenant, User $criador): Pasta
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $pasta = new Pasta();
        $pasta->setNup('TEST-NEW-' . uniqid());
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($criador);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
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

    /**
     * @return int[] IDs dos responsáveis vinculados à meta recém-criada.
     */
    private function idsResponsaveis(int $tarefaId): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $tarefa = $em->find(Tarefa::class, $tarefaId);
        self::assertNotNull($tarefa, 'a meta deveria existir no banco');

        return array_map(
            static fn(User $u): int => (int) $u->getId(),
            $tarefa->getResponsaveis()->toArray()
        );
    }

    #[TestDox('Cria meta com dois responsáveis: ambos ficam vinculados')]
    public function testCriaComMultiplosResponsaveis(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor_' . uniqid() . '@test.com');
        $resp1  = $this->criarUsuario($tenant, 'resp1_' . uniqid() . '@test.com');
        $resp2  = $this->criarUsuario($tenant, 'resp2_' . uniqid() . '@test.com');
        $pasta  = $this->criarPasta($tenant, $autor);
        $pastaId = (int) $pasta->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/tarefas/pasta/{$pastaId}/criar", [
            '_token'       => $this->gerarCsrf('tarefa_criar_' . $pastaId),
            'titulo'       => 'Meta conjunta',
            'descricao'    => 'Executada por duas pessoas',
            'responsaveis' => [(int) $resp1->getId(), (int) $resp2->getId()],
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($data['sucesso'] ?? false);

        $ids = $this->idsResponsaveis((int) $data['id']);
        self::assertCount(2, $ids);
        self::assertContains((int) $resp1->getId(), $ids);
        self::assertContains((int) $resp2->getId(), $ids);
    }

    #[TestDox('Ignora responsável de outro tenant enviado no array (filtro por tenant)')]
    public function testIgnoraResponsavelDeOutroTenant(): void
    {
        $client   = static::createClient();
        $tenantA  = $this->criarTenant();
        $tenantB  = $this->criarTenant();
        $autor    = $this->criarUsuario($tenantA, 'autor_' . uniqid() . '@test.com');
        $respA    = $this->criarUsuario($tenantA, 'respA_' . uniqid() . '@test.com');
        $estranho = $this->criarUsuario($tenantB, 'estranho_' . uniqid() . '@test.com');
        $pasta    = $this->criarPasta($tenantA, $autor);
        $pastaId  = (int) $pasta->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenantA);

        $client->request('POST', "/tarefas/pasta/{$pastaId}/criar", [
            '_token'       => $this->gerarCsrf('tarefa_criar_' . $pastaId),
            'titulo'       => 'Meta com intruso',
            'descricao'    => 'Tenta vincular alguém de outro tenant',
            'responsaveis' => [(int) $respA->getId(), (int) $estranho->getId()],
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($data['sucesso'] ?? false);

        $ids = $this->idsResponsaveis((int) $data['id']);
        self::assertSame([(int) $respA->getId()], $ids, 'apenas o responsável do tenant atual deve ser vinculado');
    }

    #[TestDox('Cria meta sem responsáveis: permitido, fica com coleção vazia')]
    public function testCriaSemResponsaveis(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor_' . uniqid() . '@test.com');
        $pasta  = $this->criarPasta($tenant, $autor);
        $pastaId = (int) $pasta->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/tarefas/pasta/{$pastaId}/criar", [
            '_token'    => $this->gerarCsrf('tarefa_criar_' . $pastaId),
            'titulo'    => 'Meta sem responsável',
            'descricao' => 'Ninguém atribuído ainda',
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($data['sucesso'] ?? false);

        self::assertSame([], $this->idsResponsaveis((int) $data['id']));
    }

    #[TestDox('PMC-044: criar pela pasta devolve o destino NA pasta — aba Metas com ?meta=<id> — e a linha da meta nova está lá')]
    public function testDestinoFicaNaPastaComAMetaNova(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor_' . uniqid() . '@test.com');
        $pasta  = $this->criarPasta($tenant, $autor);
        $pastaId = (int) $pasta->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/tarefas/pasta/{$pastaId}/criar", [
            '_token'    => $this->gerarCsrf('tarefa_criar_' . $pastaId),
            'titulo'    => 'Meta que fica na pasta',
            'descricao' => 'Depois de criar, a pasta continua aberta',
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($data['sucesso'] ?? false);
        $id = (int) $data['id'];
        self::assertSame("/pasta/{$pastaId}?meta={$id}#tarefas", $data['destino'] ?? null);

        $crawler = $client->request('GET', "/pasta/{$pastaId}?meta={$id}");
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#tarefas [data-ps-metas] .ps-metas-lista > .ps-meta[data-meta-id="' . $id . '"]'), 'a linha que o pasta-metas.js realça');
    }

    #[TestDox('Título vazio retorna 422 e não cria meta')]
    public function testTituloVazioRetorna422(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor_' . uniqid() . '@test.com');
        $pasta  = $this->criarPasta($tenant, $autor);
        $pastaId = (int) $pasta->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/tarefas/pasta/{$pastaId}/criar", [
            '_token'    => $this->gerarCsrf('tarefa_criar_' . $pastaId),
            'titulo'    => '',
            'descricao' => 'Sem título',
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    #[TestDox('CSRF inválido retorna 403 e não cria meta')]
    public function testCsrfInvalidoRetorna403(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor_' . uniqid() . '@test.com');
        $pasta  = $this->criarPasta($tenant, $autor);
        $pastaId = (int) $pasta->getId();

        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/tarefas/pasta/{$pastaId}/criar", [
            '_token'    => 'token_invalido',
            'titulo'    => 'Meta',
            'descricao' => 'Descrição',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    // =========================================================================
    // C6-SEC-03 — posse da pasta (404) e VER a pasta (403)
    // =========================================================================

    #[TestDox('C6-SEC-03: pasta de OUTRO escritório (filtro desligado) cujo criador tem vínculo nos dois → 404, nenhuma meta')]
    public function testPastaDeOutroEscritorioComCriadorNosDoisRetorna404(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        // O próprio autor está nos dois escritórios (papel de sistema em A: passa por qualquer
        // checagem de permissão) e é o criador da pasta de B. O guarda antigo, de vínculo, o
        // deixava passar: o 404 só pode vir da conferência de posse.
        $autor = $this->criarUsuario($tenantA, 'autor_' . uniqid() . '@test.com');
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new UserTenant($autor, $tenantB));
        $em->flush();
        $pastaB  = $this->criarPasta($tenantB, $autor);
        $pastaId = (int) $pastaB->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenantA);
        $notificacoesAntes = $this->contarNotificacoes();

        $this->desligarTenantFilterNaProximaRequisicao();
        $client->request('POST', "/tarefas/pasta/{$pastaId}/criar", [
            '_token'       => $this->gerarCsrf('tarefa_criar_' . $pastaId),
            'titulo'       => 'Meta na pasta alheia',
            'descricao'    => 'Não pode nascer',
            'responsaveis' => [(int) $autor->getId()],
        ]);

        $this->assertTenantFilterFoiDesligado();
        self::assertResponseStatusCodeSame(404);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Pasta não encontrada.', $data['erro'] ?? null);
        self::assertSame(0, $this->contarMetasDaPasta($pastaId));
        self::assertSame($notificacoesAntes, $this->contarNotificacoes());
    }

    #[TestDox('controle: a PRÓPRIA pasta com o filtro desligado continua criando a meta')]
    public function testPropriaPastaComFiltroDesligadoCria(): void
    {
        $client  = static::createClient();
        $tenant  = $this->criarTenant();
        $autor   = $this->criarUsuario($tenant, 'autor_' . uniqid() . '@test.com');
        $pastaId = (int) $this->criarPasta($tenant, $autor)->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $this->desligarTenantFilterNaProximaRequisicao();
        $client->request('POST', "/tarefas/pasta/{$pastaId}/criar", [
            '_token'    => $this->gerarCsrf('tarefa_criar_' . $pastaId),
            'titulo'    => 'Meta legítima',
            'descricao' => 'Na pasta do próprio escritório',
        ]);

        $this->assertTenantFilterFoiDesligado();
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->contarMetasDaPasta($pastaId));
    }

    #[TestDox('C6-SEC-03: com o módulo Tarefas mas SEM ver a pasta → 403, nenhuma meta, nenhuma notificação')]
    public function testSemVerAPastaRetorna403(): void
    {
        $client   = static::createClient();
        $tenant   = $this->criarTenant();
        $dono     = $this->criarUsuario($tenant, 'dono_' . uniqid() . '@test.com');
        $resp     = $this->criarUsuario($tenant, 'resp_' . uniqid() . '@test.com');
        $soModulo = $this->criarUsuarioComPermissoes($tenant, ['modules.tarefas.view']);
        $pastaId  = (int) $this->criarPasta($tenant, $dono)->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $soModulo, $tenant);
        $notificacoesAntes = $this->contarNotificacoes();

        $client->request('POST', "/tarefas/pasta/{$pastaId}/criar", [
            '_token'       => $this->gerarCsrf('tarefa_criar_' . $pastaId),
            'titulo'       => 'Meta de quem não vê',
            'descricao'    => 'Não pode nascer',
            'responsaveis' => [(int) $resp->getId()],
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->contarMetasDaPasta($pastaId));
        self::assertSame($notificacoesAntes, $this->contarNotificacoes());
    }

    #[TestDox('C6-SEC-03 (irmão): o mesmo perfil COM resources.pasta.view cria a meta e avisa o responsável')]
    public function testComVerAPastaCria(): void
    {
        $client   = static::createClient();
        $tenant   = $this->criarTenant();
        $dono     = $this->criarUsuario($tenant, 'dono_' . uniqid() . '@test.com');
        $resp     = $this->criarUsuario($tenant, 'resp_' . uniqid() . '@test.com');
        $comVer   = $this->criarUsuarioComPermissoes($tenant, ['modules.tarefas.view', 'resources.pasta.view']);
        $pastaId  = (int) $this->criarPasta($tenant, $dono)->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $comVer, $tenant);
        $notificacoesAntes = $this->contarNotificacoes();

        $client->request('POST', "/tarefas/pasta/{$pastaId}/criar", [
            '_token'       => $this->gerarCsrf('tarefa_criar_' . $pastaId),
            'titulo'       => 'Meta de quem vê',
            'descricao'    => 'Pode nascer',
            'responsaveis' => [(int) $resp->getId()],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->contarMetasDaPasta($pastaId));
        self::assertSame($notificacoesAntes + 1, $this->contarNotificacoes());
    }

    /**
     * Usuário de papel comum (sem bypass do PermissionChecker) com exatamente estas permissões.
     *
     * @param list<string> $codigos
     */
    private function criarUsuarioComPermissoes(Tenant $tenant, array $codigos): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Papel restrito ' . uniqid());
        $role->setIsSystem(false);
        $em->persist($role);

        foreach ($codigos as $codigo) {
            $perm = $em->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
            if ($perm === null) {
                $perm = new Permission();
                $perm->setCode($codigo);
                $perm->setDescription($codigo);
                $perm->setGroup(explode('.', $codigo)[0]);
                $em->persist($perm);
            }

            $vinculo = new TenantRolePermission();
            $vinculo->setTenantRole($role);
            $vinculo->setPermission($perm);
            $em->persist($vinculo);
            $role->getTenantRolePermissions()->add($vinculo);
        }

        $user = new User();
        $user->setEmail('restrito_' . uniqid() . '@test.com');
        $user->setFullName('Usuário restrito ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }

    #[TestDox('A meta criada pelo modal ganha o número da sequência do ESCRITÓRIO (nunca o id), um escritório sem interferir no outro')]
    public function testMetaCriadaGanhaONumeroDoEscritorio(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $autorA  = $this->criarUsuario($tenantA, 'autorA_' . uniqid() . '@test.com');
        $autorB  = $this->criarUsuario($tenantB, 'autorB_' . uniqid() . '@test.com');
        $pastaA  = (int) $this->criarPasta($tenantA, $autorA)->getId();
        $pastaB  = (int) $this->criarPasta($tenantB, $autorB)->getId();

        // Três POSTs no mesmo cliente: sem reiniciar o kernel entre eles, o CSRF previsível continua valendo.
        $client->disableReboot();
        $this->instalarCsrfStorage();
        $criar = function (int $pastaId, string $titulo) use ($client): int {
            $client->request('POST', "/tarefas/pasta/{$pastaId}/criar", [
                '_token'    => $this->gerarCsrf('tarefa_criar_' . $pastaId),
                'titulo'    => $titulo,
                'descricao' => 'Numerada ao nascer',
            ]);
            self::assertResponseIsSuccessful();

            return (int) json_decode((string) $client->getResponse()->getContent(), true)['id'];
        };

        $this->logarComTenant($client, $autorA, $tenantA);
        $primeiraA = $criar($pastaA, 'Primeira de A');
        $segundaA  = $criar($pastaA, 'Segunda de A');
        $this->logarComTenant($client, $autorB, $tenantB);
        $primeiraB = $criar($pastaB, 'Primeira de B');

        $numero = static fn (int $id): ?int => ($n = static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT numero FROM tarefa WHERE id = ?', [$id])) === null ? null : (int) $n;

        self::assertSame(1, $numero($primeiraA));
        self::assertSame(2, $numero($segundaA));
        self::assertSame(1, $numero($primeiraB), 'B começa a própria sequência: as metas de A não contam');
    }

    private function contarMetasDaPasta(int $pastaId): int
    {
        return (int) static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM tarefa WHERE pasta_id = ?', [$pastaId]);
    }

    private function contarNotificacoes(): int
    {
        return (int) static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM notificacao');
    }
}
