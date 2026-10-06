<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Entity\Pasta;
use App\Tarefa\Controller\MetaNaListaController;
use App\Tarefa\UseCase\AlertarResponsavelDaMetaUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * L9 da Trilha B — ações da meta na própria lista da pasta: `tarefa_renomear`,
 * `tarefa_reabrir` e `tarefa_alertar` (sino "Alertar para verificar").
 *
 * Cada POST é provado em quatro frentes: o caminho feliz, o CSRF, o usuário sem o
 * módulo Tarefas (403) e o escritório vizinho (404 pelo TenantFilter). O 404 só vale
 * como prova da guarda porque o MESMO usuário, com o MESMO token previsível, consegue
 * agir sobre a meta irmã do próprio escritório no mesmo teste.
 */
#[CoversClass(MetaNaListaController::class)]
#[Group('tarefa')]
final class MetaNaListaControllerTest extends JusPrimeWebTestCase
{
    // =========================================================================
    // Fixtures
    // =========================================================================

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Metas L9 ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    /** Usuário comum com papel de sistema (bypass do PermissionChecker, sem super admin). */
    private function criarUsuario(Tenant $tenant, string $nome, bool $papelDeSistema = true): User
    {
        $em     = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('metas_l9_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Papel L9 ' . uniqid());
        $role->setIsSystem($papelDeSistema);
        $em->persist($role);

        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $em->persist($ut);
        $em->flush();

        return $user;
    }

    private function criarPasta(Tenant $tenant, User $criador): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('L9-' . uniqid());
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($criador);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    /** @param User[] $responsaveis */
    private function criarMeta(Pasta $pasta, User $autor, array $responsaveis, string $status = Tarefa::STATUS_PENDENTE, string $titulo = 'Protocolar recurso'): Tarefa
    {
        $meta = new Tarefa();
        $meta->setTitulo($titulo);
        $meta->setDescricao('...');
        $meta->setPrazo(new \DateTimeImmutable('-3 days midnight'));
        $meta->setStatus($status);
        if ($status === Tarefa::STATUS_CONCLUIDA) {
            $meta->setDataConclusao(new \DateTimeImmutable('-1 day'));
        }
        $meta->setPasta($pasta);
        $meta->setTenant($pasta->getTenant());
        $meta->setCriadoPor($autor);
        foreach ($responsaveis as $r) {
            $meta->addResponsavel($r);
        }
        $this->em()->persist($meta);
        $this->em()->flush();

        return $meta;
    }

    /** Token CSRF previsível (`TOKEN_<id>`), para o POST direto provar a GUARDA e não o CSRF. */
    private function instalarCsrfPrevisivel(): void
    {
        static::getContainer()->set('security.csrf.token_storage', new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        });
    }

    /** Leitura de conferência no banco, sem o TenantFilter do último request. */
    private function metaNoBanco(int $id): Tarefa
    {
        $filtros = $this->em()->getFilters();
        if ($filtros->isEnabled('tenant')) {
            $filtros->disable('tenant');
        }
        $this->em()->clear();
        $meta = $this->em()->find(Tarefa::class, $id);
        self::assertNotNull($meta, 'a meta sumiu do banco');

        return $meta;
    }

    private function contarAlertas(int $metaId, int $usuarioId): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM notificacao WHERE tarefa_id = :t AND usuario_id = :u AND tipo = :tipo',
            ['t' => $metaId, 'u' => $usuarioId, 'tipo' => AlertarResponsavelDaMetaUseCase::TIPO_NOTIFICACAO],
        );
    }

    /** @return array{KernelBrowser, Tenant, User, User, Pasta} cliente, escritório, autor (logado), responsável, pasta */
    private function cenario(): array
    {
        $client      = static::createClient();
        $tenant      = $this->criarTenant();
        $autor       = $this->criarUsuario($tenant, 'Ana Autora');
        $responsavel = $this->criarUsuario($tenant, 'Bruno Responsável');
        $pasta       = $this->criarPasta($tenant, $autor);

        // Vários POSTs por teste: sem isso o reboot do kernel entre requisições descarta o
        // token storage previsível e o 2º POST cairia no CSRF (403) — o que faria o teste do
        // limite por hora passar pelo motivo errado.
        $client->disableReboot();
        $this->instalarCsrfPrevisivel();
        $this->logarComTenant($client, $autor, $tenant);

        return [$client, $tenant, $autor, $responsavel, $pasta];
    }

    // =========================================================================
    // Renomear
    // =========================================================================

    #[TestDox('renomear: grava o nome normalizado (espaços) como digitado e volta para a aba Metas da pasta')]
    public function testRenomear(): void
    {
        [$client, , $autor, $resp, $pasta] = $this->cenario();
        $id = (int) $this->criarMeta($pasta, $autor, [$resp])->getId();

        $client->request('POST', '/tarefas/' . $id . '/renomear', ['_token' => 'TOKEN_renomear_tarefa_' . $id, 'titulo' => '  Protocolar   apelação  ']);

        self::assertResponseRedirects('/pasta/' . $pasta->getId() . '#tarefas');
        self::assertSame('Protocolar apelação', $this->metaNoBanco($id)->getTitulo());
    }

    #[TestDox('renomear: nome vazio (ou só espaço) não grava')]
    public function testRenomearVazioNaoGrava(): void
    {
        [$client, , $autor, $resp, $pasta] = $this->cenario();
        $id = (int) $this->criarMeta($pasta, $autor, [$resp])->getId();

        $client->request('POST', '/tarefas/' . $id . '/renomear', ['_token' => 'TOKEN_renomear_tarefa_' . $id, 'titulo' => '   ']);

        self::assertResponseRedirects('/pasta/' . $pasta->getId() . '#tarefas');
        self::assertSame('Protocolar recurso', $this->metaNoBanco($id)->getTitulo());
    }

    #[TestDox('renomear: token inválido → 403 e o nome não muda')]
    public function testRenomearCsrf(): void
    {
        [$client, , $autor, $resp, $pasta] = $this->cenario();
        $id = (int) $this->criarMeta($pasta, $autor, [$resp])->getId();

        $client->request('POST', '/tarefas/' . $id . '/renomear', ['_token' => 'invalido', 'titulo' => 'Outro']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Protocolar recurso', $this->metaNoBanco($id)->getTitulo());
    }

    #[TestDox('renomear: usuário do escritório SEM o módulo Tarefas → 403 e o nome não muda')]
    public function testRenomearSemPermissao(): void
    {
        [$client, $tenant, $autor, $resp, $pasta] = $this->cenario();
        $id      = (int) $this->criarMeta($pasta, $autor, [$resp])->getId();
        $semNada = $this->criarUsuario($tenant, 'Sem módulo', false);

        $this->logarComTenant($client, $semNada, $tenant);
        $client->request('POST', '/tarefas/' . $id . '/renomear', ['_token' => 'TOKEN_renomear_tarefa_' . $id, 'titulo' => 'Outro']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Protocolar recurso', $this->metaNoBanco($id)->getTitulo());
    }

    #[TestDox('renomear: meta de OUTRO escritório → 404; a irmã do próprio escritório renomeia')]
    public function testRenomearCrossTenant(): void
    {
        [$client, , $autor, $resp, $pasta] = $this->cenario();
        $idA = (int) $this->criarMeta($pasta, $autor, [$resp])->getId();

        $tenantB = $this->criarTenant();
        $userB   = $this->criarUsuario($tenantB, 'Usuário B');
        $idB     = (int) $this->criarMeta($this->criarPasta($tenantB, $userB), $userB, [$userB])->getId();

        $this->logarComTenant($client, $userB, $tenantB);
        $client->request('POST', '/tarefas/' . $idB . '/renomear', ['_token' => 'TOKEN_renomear_tarefa_' . $idB, 'titulo' => 'Renomeada por B']);
        self::assertResponseRedirects();

        $client->request('POST', '/tarefas/' . $idA . '/renomear', ['_token' => 'TOKEN_renomear_tarefa_' . $idA, 'titulo' => 'Invadida']);
        self::assertResponseStatusCodeSame(404);

        self::assertSame('Protocolar recurso', $this->metaNoBanco($idA)->getTitulo());
        self::assertSame('Renomeada por B', $this->metaNoBanco($idB)->getTitulo());
    }

    // =========================================================================
    // Reabrir
    // =========================================================================

    #[TestDox('reabrir: concluída volta a pendente, sem data de conclusão, e volta para a aba Metas')]
    public function testReabrir(): void
    {
        [$client, , $autor, $resp, $pasta] = $this->cenario();
        $id = (int) $this->criarMeta($pasta, $autor, [$resp], Tarefa::STATUS_CONCLUIDA)->getId();

        $client->request('POST', '/tarefas/' . $id . '/reabrir', ['_token' => 'TOKEN_reabrir_tarefa_' . $id]);

        self::assertResponseRedirects('/pasta/' . $pasta->getId() . '#tarefas');
        $meta = $this->metaNoBanco($id);
        self::assertSame(Tarefa::STATUS_PENDENTE, $meta->getStatus());
        self::assertNull($meta->getDataConclusao());
    }

    #[TestDox('reabrir: meta em revisão não é tocada (só concluída reabre)')]
    public function testReabrirNaoMexeEmMetaAberta(): void
    {
        [$client, , $autor, $resp, $pasta] = $this->cenario();
        $id = (int) $this->criarMeta($pasta, $autor, [$resp], Tarefa::STATUS_EM_REVISAO)->getId();

        $client->request('POST', '/tarefas/' . $id . '/reabrir', ['_token' => 'TOKEN_reabrir_tarefa_' . $id]);

        self::assertResponseRedirects();
        self::assertSame(Tarefa::STATUS_EM_REVISAO, $this->metaNoBanco($id)->getStatus());
    }

    #[TestDox('reabrir: token inválido → 403 e continua concluída')]
    public function testReabrirCsrf(): void
    {
        [$client, , $autor, $resp, $pasta] = $this->cenario();
        $id = (int) $this->criarMeta($pasta, $autor, [$resp], Tarefa::STATUS_CONCLUIDA)->getId();

        $client->request('POST', '/tarefas/' . $id . '/reabrir', ['_token' => 'invalido']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(Tarefa::STATUS_CONCLUIDA, $this->metaNoBanco($id)->getStatus());
    }

    #[TestDox('reabrir: usuário do escritório SEM o módulo Tarefas → 403 e continua concluída')]
    public function testReabrirSemPermissao(): void
    {
        [$client, $tenant, $autor, $resp, $pasta] = $this->cenario();
        $id      = (int) $this->criarMeta($pasta, $autor, [$resp], Tarefa::STATUS_CONCLUIDA)->getId();
        $semNada = $this->criarUsuario($tenant, 'Sem módulo', false);

        $this->logarComTenant($client, $semNada, $tenant);
        $client->request('POST', '/tarefas/' . $id . '/reabrir', ['_token' => 'TOKEN_reabrir_tarefa_' . $id]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(Tarefa::STATUS_CONCLUIDA, $this->metaNoBanco($id)->getStatus());
    }

    #[TestDox('reabrir: meta de OUTRO escritório → 404; a irmã do próprio escritório reabre')]
    public function testReabrirCrossTenant(): void
    {
        [$client, , $autor, $resp, $pasta] = $this->cenario();
        $idA = (int) $this->criarMeta($pasta, $autor, [$resp], Tarefa::STATUS_CONCLUIDA)->getId();

        $tenantB = $this->criarTenant();
        $userB   = $this->criarUsuario($tenantB, 'Usuário B');
        $idB     = (int) $this->criarMeta($this->criarPasta($tenantB, $userB), $userB, [$userB], Tarefa::STATUS_CONCLUIDA)->getId();

        $this->logarComTenant($client, $userB, $tenantB);
        $client->request('POST', '/tarefas/' . $idB . '/reabrir', ['_token' => 'TOKEN_reabrir_tarefa_' . $idB]);
        self::assertResponseRedirects();

        $client->request('POST', '/tarefas/' . $idA . '/reabrir', ['_token' => 'TOKEN_reabrir_tarefa_' . $idA]);
        self::assertResponseStatusCodeSame(404);

        self::assertSame(Tarefa::STATUS_CONCLUIDA, $this->metaNoBanco($idA)->getStatus());
        self::assertNotNull($this->metaNoBanco($idA)->getDataConclusao());
        self::assertSame(Tarefa::STATUS_PENDENTE, $this->metaNoBanco($idB)->getStatus());
    }

    // =========================================================================
    // Alertar (sino)
    // =========================================================================

    #[TestDox('alertar: o responsável escolhido recebe UMA notificação real, ligada à meta, no escritório da meta')]
    public function testAlertar(): void
    {
        [$client, $tenant, $autor, $resp, $pasta] = $this->cenario();
        $outro = $this->criarUsuario($tenant, 'Carla Também Responsável');
        $id    = (int) $this->criarMeta($pasta, $autor, [$resp, $outro])->getId();

        $client->request('POST', '/tarefas/' . $id . '/alertar', ['_token' => 'TOKEN_alertar_tarefa_' . $id, 'usuario' => (string) $resp->getId()]);

        self::assertResponseRedirects('/pasta/' . $pasta->getId() . '#tarefas');
        self::assertSame(1, $this->contarAlertas($id, (int) $resp->getId()));
        self::assertSame(0, $this->contarAlertas($id, (int) $outro->getId()), 'só quem foi escolhido é alertado');

        $this->em()->clear();
        $n = $this->em()->getRepository(Notificacao::class)->findOneBy(['tipo' => AlertarResponsavelDaMetaUseCase::TIPO_NOTIFICACAO, 'tarefa' => $id]);
        self::assertNotNull($n);
        self::assertSame($tenant->getId(), $n->getTenant()?->getId());
        self::assertFalse($n->isLida());
        self::assertStringContainsString('Ana Autora pediu que você verifique a meta "Protocolar recurso"', (string) $n->getMensagem());
        self::assertStringContainsString('3 dias em atraso', (string) $n->getMensagem());
    }

    #[TestDox('alertar: o mesmo responsável não é alertado de novo sobre a mesma meta dentro de 1 hora; passada a hora, pode')]
    public function testAlertarLimitaUmPorHora(): void
    {
        [$client, , $autor, $resp, $pasta] = $this->cenario();
        $id     = (int) $this->criarMeta($pasta, $autor, [$resp])->getId();
        $params = ['_token' => 'TOKEN_alertar_tarefa_' . $id, 'usuario' => (string) $resp->getId()];

        $client->request('POST', '/tarefas/' . $id . '/alertar', $params);
        $client->request('POST', '/tarefas/' . $id . '/alertar', $params);
        self::assertResponseRedirects();
        self::assertSame(1, $this->contarAlertas($id, (int) $resp->getId()), 'o segundo clique na mesma hora não gera outra notificação');

        // A última notificação passa a ter 61 minutos: o próximo alerta é aceito.
        $this->em()->getConnection()->executeStatement(
            "UPDATE notificacao SET criada_em = criada_em - INTERVAL '61 minutes' WHERE tarefa_id = :t AND tipo = :tipo",
            ['t' => $id, 'tipo' => AlertarResponsavelDaMetaUseCase::TIPO_NOTIFICACAO],
        );
        $client->request('POST', '/tarefas/' . $id . '/alertar', $params);
        self::assertSame(2, $this->contarAlertas($id, (int) $resp->getId()));
    }

    #[TestDox('alertar: quem não é responsável da meta, o próprio usuário e meta concluída são recusados sem notificação')]
    public function testAlertarRecusas(): void
    {
        [$client, $tenant, $autor, $resp, $pasta] = $this->cenario();
        $naoResp   = $this->criarUsuario($tenant, 'Não Responsável');
        $aberta    = (int) $this->criarMeta($pasta, $autor, [$resp, $autor])->getId();
        $concluida = (int) $this->criarMeta($pasta, $autor, [$resp], Tarefa::STATUS_CONCLUIDA)->getId();

        $client->request('POST', '/tarefas/' . $aberta . '/alertar', ['_token' => 'TOKEN_alertar_tarefa_' . $aberta, 'usuario' => (string) $naoResp->getId()]);
        self::assertResponseRedirects();
        $client->request('POST', '/tarefas/' . $aberta . '/alertar', ['_token' => 'TOKEN_alertar_tarefa_' . $aberta, 'usuario' => (string) $autor->getId()]);
        self::assertResponseRedirects();
        $client->request('POST', '/tarefas/' . $concluida . '/alertar', ['_token' => 'TOKEN_alertar_tarefa_' . $concluida, 'usuario' => (string) $resp->getId()]);
        self::assertResponseRedirects();

        self::assertSame(0, $this->contarAlertas($aberta, (int) $naoResp->getId()));
        self::assertSame(0, $this->contarAlertas($aberta, (int) $autor->getId()));
        self::assertSame(0, $this->contarAlertas($concluida, (int) $resp->getId()));
    }

    #[TestDox('alertar: token inválido → 403 e nenhuma notificação')]
    public function testAlertarCsrf(): void
    {
        [$client, , $autor, $resp, $pasta] = $this->cenario();
        $id = (int) $this->criarMeta($pasta, $autor, [$resp])->getId();

        $client->request('POST', '/tarefas/' . $id . '/alertar', ['_token' => 'invalido', 'usuario' => (string) $resp->getId()]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->contarAlertas($id, (int) $resp->getId()));
    }

    #[TestDox('alertar: usuário do escritório SEM o módulo Tarefas → 403 e nenhuma notificação')]
    public function testAlertarSemPermissao(): void
    {
        [$client, $tenant, $autor, $resp, $pasta] = $this->cenario();
        $id      = (int) $this->criarMeta($pasta, $autor, [$resp])->getId();
        $semNada = $this->criarUsuario($tenant, 'Sem módulo', false);

        $this->logarComTenant($client, $semNada, $tenant);
        $client->request('POST', '/tarefas/' . $id . '/alertar', ['_token' => 'TOKEN_alertar_tarefa_' . $id, 'usuario' => (string) $resp->getId()]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->contarAlertas($id, (int) $resp->getId()));
    }

    #[TestDox('alertar: meta de OUTRO escritório → 404 e nenhuma notificação; a irmã do próprio escritório alerta')]
    public function testAlertarCrossTenant(): void
    {
        [$client, , $autor, $resp, $pasta] = $this->cenario();
        $idA = (int) $this->criarMeta($pasta, $autor, [$resp])->getId();

        $tenantB = $this->criarTenant();
        $userB   = $this->criarUsuario($tenantB, 'Usuário B');
        $respB   = $this->criarUsuario($tenantB, 'Responsável B');
        $idB     = (int) $this->criarMeta($this->criarPasta($tenantB, $userB), $userB, [$respB])->getId();

        $this->logarComTenant($client, $userB, $tenantB);
        $client->request('POST', '/tarefas/' . $idB . '/alertar', ['_token' => 'TOKEN_alertar_tarefa_' . $idB, 'usuario' => (string) $respB->getId()]);
        self::assertResponseRedirects();

        $client->request('POST', '/tarefas/' . $idA . '/alertar', ['_token' => 'TOKEN_alertar_tarefa_' . $idA, 'usuario' => (string) $resp->getId()]);
        self::assertResponseStatusCodeSame(404);

        self::assertSame(0, $this->contarAlertas($idA, (int) $resp->getId()));
        self::assertSame(1, $this->contarAlertas($idB, (int) $respB->getId()));
    }
}
