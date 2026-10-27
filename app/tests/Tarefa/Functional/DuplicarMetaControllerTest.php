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
use App\Pasta\Entity\PrioridadePasta;
use App\Tarefa\Controller\DuplicarMetaController;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Enum\PrioridadeDaMeta;
use App\Tarefa\Enum\TipoDeDemanda;
use App\Tarefa\UseCase\DuplicarMetaUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\DesligaTenantFilterNaRequisicao;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * PMC-032 — "Duplicar" no ⋮ da meta (POST `tarefa_duplicar`, desenho 02 D:3725/D:3736).
 *
 * A cópia é uma meta nova da mesma pasta, então as guardas são as da criação (`tarefa_criar_para_pasta`): meta de
 * outro escritório → 404, sem VER a pasta → 403, CSRF inválido → 403, pasta excluída (lápide) → recusa do
 * `PastaSomenteLeituraListener`. Em nenhuma recusa nasce cópia. A cópia leva os campos da original, ganha
 * " (cópia)" no título e NÃO notifica ninguém. O "Desfazer" do aviso é o `tarefa_excluir` da cópia por XHR.
 */
#[CoversClass(DuplicarMetaController::class)]
#[CoversClass(DuplicarMetaUseCase::class)]
#[CoversClass(TarefaController::class)]
#[Group('tarefa')]
final class DuplicarMetaControllerTest extends JusPrimeWebTestCase
{
    use DesligaTenantFilterNaRequisicao;

    private const XHR = ['HTTP_X-Requested-With' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'];

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
        $tenant->setName('Tenant Duplicar ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    private function criarUsuario(Tenant $tenant, string $nome): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('duplicar_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em()->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Administrador ' . uniqid());
        $role->setIsSystem(true);
        $this->em()->persist($role);

        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($role);
        $this->em()->persist($vinculo);
        $this->em()->flush();

        return $user;
    }

    /**
     * Papel comum (sem bypass do PermissionChecker) com exatamente estas permissões.
     *
     * @param list<string> $codigos
     */
    private function criarUsuarioComPermissoes(Tenant $tenant, array $codigos): User
    {
        $em   = $this->em();
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
            $ligacao = new TenantRolePermission();
            $ligacao->setTenantRole($role);
            $ligacao->setPermission($perm);
            $em->persist($ligacao);
            $role->getTenantRolePermissions()->add($ligacao);
        }

        $user = new User();
        $user->setEmail('restrito_' . uniqid() . '@test.com');
        $user->setFullName('Usuário restrito');
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

    private function criarPasta(Tenant $tenant, User $criador, bool $excluida = false): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('DUP-' . uniqid());
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($criador);
        if ($excluida) {
            $pasta->marcarExcluida($criador, new \DateTimeImmutable());
        }
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    /** @param list<User> $responsaveis */
    private function criarMeta(Pasta $pasta, User $autor, array $responsaveis, string $titulo = 'Protocolar recurso'): Tarefa
    {
        $meta = new Tarefa();
        $meta->setTitulo($titulo);
        $meta->setDescricao('Prazo de 15 dias úteis da intimação');
        $meta->setPrazo(new \DateTimeImmutable('2026-11-20'));
        $meta->setPrioridade(PrioridadeDaMeta::Urgente);
        $meta->setTipoDeDemanda(TipoDeDemanda::Consultoria);
        $meta->setStatus(Tarefa::STATUS_CONCLUIDA);
        $meta->setDataConclusao(new \DateTimeImmutable('-1 day'));
        $meta->setPasta($pasta);
        $meta->setTenant($pasta->getTenant());
        $meta->setCriadoPor($autor);
        foreach ($responsaveis as $responsavel) {
            $meta->addResponsavel($responsavel);
        }
        $this->em()->persist($meta);
        // A primeira requisição do cliente usa o MESMO EntityManager do teste: a coleção da pasta tem de saber da meta.
        $pasta->getTarefas()->add($meta);
        $this->em()->flush();

        return $meta;
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class () implements ClearableTokenStorageInterface {
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
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    private function duplicar(KernelBrowser $client, int $metaId, bool $xhr = true, ?string $token = null): void
    {
        $client->request('POST', "/tarefas/{$metaId}/duplicar", ['_token' => $token ?? 'TOKEN_duplicar_tarefa_' . $metaId], [], $xhr ? self::XHR : []);
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        return (array) json_decode((string) $client->getResponse()->getContent(), true);
    }

    private function contarMetasDaPasta(int $pastaId): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM tarefa WHERE pasta_id = ?', [$pastaId]);
    }

    private function contarNotificacoes(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM notificacao');
    }

    // =========================================================================
    // Caminho feliz
    // =========================================================================

    #[TestDox('XHR: a cópia leva descrição, prazo, responsáveis, prioridade e tipo; título com " (cópia)"; nasce aberta, por quem duplicou, numerada; ninguém é notificado')]
    public function testDuplicaCopiandoOsCamposSemNotificar(): void
    {
        $client   = static::createClient();
        $tenant   = $this->criarTenant();
        $autor    = $this->criarUsuario($tenant, 'Ana Autora');
        $colega   = $this->criarUsuario($tenant, 'Bruno Colega');
        $pasta    = $this->criarPasta($tenant, $autor);
        $original = $this->criarMeta($pasta, $autor, [$autor, $colega]);
        $pastaId  = (int) $pasta->getId();
        $numeroDaOriginal = (int) $this->em()->getConnection()->fetchOne('SELECT COALESCE(MAX(numero), 0) FROM tarefa WHERE tenant_id = ?', [$tenant->getId()]);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $colega, $tenant);
        $notificacoesAntes = $this->contarNotificacoes();

        $this->duplicar($client, (int) $original->getId());

        self::assertResponseIsSuccessful();
        $dados = $this->json($client);
        self::assertTrue($dados['sucesso'] ?? false);
        self::assertSame('Meta duplicada', $dados['mensagem'] ?? null);
        $copiaId = (int) ($dados['copia'] ?? 0);
        self::assertGreaterThan(0, $copiaId);
        self::assertNotSame((int) $original->getId(), $copiaId);
        self::assertSame(2, $this->contarMetasDaPasta($pastaId));
        self::assertSame($notificacoesAntes, $this->contarNotificacoes(), 'duplicar não notifica (o desenho não dispara evento)');

        $this->em()->clear();
        $copia = $this->em()->find(Tarefa::class, $copiaId);
        self::assertNotNull($copia);
        self::assertSame('Protocolar recurso (cópia)', $copia->getTitulo());
        self::assertSame('Prazo de 15 dias úteis da intimação', $copia->getDescricao());
        self::assertSame('2026-11-20', $copia->getPrazo()?->format('Y-m-d'));
        self::assertSame(PrioridadeDaMeta::Urgente, $copia->getPrioridade());
        self::assertSame(TipoDeDemanda::Consultoria, $copia->getTipoDeDemanda());
        self::assertSame(Tarefa::STATUS_PENDENTE, $copia->getStatus());
        self::assertNull($copia->getDataConclusao());
        self::assertSame($pastaId, $copia->getPasta()->getId());
        self::assertSame($colega->getId(), $copia->getCriadoPor()?->getId(), 'quem duplicou é o criador (o Desfazer exclui como criador)');
        self::assertSame($numeroDaOriginal + 1, $copia->getNumero(), 'o próximo número do escritório');
        $ids = array_map(static fn (User $u): int => (int) $u->getId(), $copia->getResponsaveis()->toArray());
        sort($ids);
        $esperados = [(int) $autor->getId(), (int) $colega->getId()];
        sort($esperados);
        self::assertSame($esperados, $ids);
    }

    #[TestDox('XHR: a aba volta renderizada com a cópia na ÚLTIMA linha e o "Excluir" dela (o Desfazer)')]
    public function testRespostaTrazAAbaComACopiaNoFim(): void
    {
        $client   = static::createClient();
        $tenant   = $this->criarTenant();
        $autor    = $this->criarUsuario($tenant, 'Ana Autora');
        $pasta    = $this->criarPasta($tenant, $autor);
        $original = $this->criarMeta($pasta, $autor, [$autor]);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);
        $this->duplicar($client, (int) $original->getId());

        self::assertResponseIsSuccessful();
        $dados   = $this->json($client);
        $copiaId = (int) $dados['copia'];
        $aba     = new Crawler((string) $dados['html']);
        $linhas  = $aba->filter('[data-ps-metas] > .ps-metas-lista > article.ps-meta');
        self::assertSame([(string) $original->getId(), (string) $copiaId], $linhas->each(static fn (Crawler $l): string => (string) $l->attr('data-meta-id')));
        self::assertSame('aberta', $linhas->last()->attr('data-meta-estado'), 'nasce aberta');
        self::assertCount(1, $linhas->last()->filter('.ps-meta-acoes > .ps-pop--meta > form.ps-meta-excluir[action="/tarefas/' . $copiaId . '/excluir"]'));
    }

    #[TestDox('sem JS: volta para a pasta com a cópia realçada (?meta=), como a criação')]
    public function testSemXhrVoltaParaAPastaComACopia(): void
    {
        $client   = static::createClient();
        $tenant   = $this->criarTenant();
        $autor    = $this->criarUsuario($tenant, 'Ana Autora');
        $pasta    = $this->criarPasta($tenant, $autor);
        $original = $this->criarMeta($pasta, $autor, [$autor]);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);
        $this->duplicar($client, (int) $original->getId(), false);

        $copiaId = (int) $this->em()->getConnection()->fetchOne('SELECT MAX(id) FROM tarefa WHERE pasta_id = ?', [$pasta->getId()]);
        self::assertNotSame((int) $original->getId(), $copiaId);
        self::assertResponseRedirects('/pasta/' . $pasta->getId() . '?meta=' . $copiaId . '#tarefas');
    }

    #[TestDox('título longo: a cópia corta o original para caber em 120 com " (cópia)"')]
    public function testTituloLongoCabeNoLimite(): void
    {
        $client   = static::createClient();
        $tenant   = $this->criarTenant();
        $autor    = $this->criarUsuario($tenant, 'Ana Autora');
        $pasta    = $this->criarPasta($tenant, $autor);
        $original = $this->criarMeta($pasta, $autor, [$autor], str_repeat('ç', 200));

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);
        $this->duplicar($client, (int) $original->getId());

        self::assertResponseIsSuccessful();
        $titulo = (string) $this->em()->getConnection()->fetchOne('SELECT titulo FROM tarefa WHERE id = ?', [(int) $this->json($client)['copia']]);
        self::assertSame(str_repeat('ç', 112) . ' (cópia)', $titulo);
        self::assertSame(120, mb_strlen($titulo));
    }

    #[TestDox('Desfazer: o "Excluir" da cópia por XHR apaga só a cópia e devolve a aba sem ela')]
    public function testDesfazerApagaACopia(): void
    {
        $client   = static::createClient();
        $client->disableReboot();
        $tenant   = $this->criarTenant();
        $autor    = $this->criarUsuario($tenant, 'Ana Autora');
        $pasta    = $this->criarPasta($tenant, $autor);
        $original = $this->criarMeta($pasta, $autor, [$autor]);
        $pastaId  = (int) $pasta->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);
        $this->duplicar($client, (int) $original->getId());
        $copiaId = (int) $this->json($client)['copia'];
        self::assertSame(2, $this->contarMetasDaPasta($pastaId));

        $client->request('POST', "/tarefas/{$copiaId}/excluir", ['_token' => 'TOKEN_excluir_tarefa_' . $copiaId], [], self::XHR);

        self::assertResponseIsSuccessful();
        $dados = $this->json($client);
        self::assertTrue($dados['sucesso'] ?? false);
        self::assertSame(1, $this->contarMetasDaPasta($pastaId));
        $ids = (new Crawler((string) $dados['html']))->filter('[data-ps-metas] > .ps-metas-lista > article.ps-meta')
            ->each(static fn (Crawler $l): string => (string) $l->attr('data-meta-id'));
        self::assertSame([(string) $original->getId()], $ids, 'a aba devolvida já não tem a cópia');
    }

    // =========================================================================
    // Recusas: nenhuma cópia nasce
    // =========================================================================

    #[TestDox('meta de OUTRO escritório (filtro desligado), quem duplica está nos dois → 404, nenhuma cópia')]
    public function testOutroEscritorioRetorna404(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $autor   = $this->criarUsuario($tenantA, 'Ana Autora');
        $this->em()->persist(new UserTenant($autor, $tenantB));
        $this->em()->flush();
        $pastaB  = $this->criarPasta($tenantB, $autor);
        $metaB   = $this->criarMeta($pastaB, $autor, [$autor]);
        $pastaId = (int) $pastaB->getId();
        $metaId  = (int) $metaB->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenantA);
        $this->desligarTenantFilterNaProximaRequisicao();
        $this->duplicar($client, $metaId);

        $this->assertTenantFilterFoiDesligado();
        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, $this->contarMetasDaPasta($pastaId));
    }

    #[TestDox('com o módulo Tarefas mas SEM ver a pasta → 403, nenhuma cópia')]
    public function testSemVerAPastaRetorna403(): void
    {
        $client   = static::createClient();
        $tenant   = $this->criarTenant();
        $dono     = $this->criarUsuario($tenant, 'Dona da pasta');
        $soModulo = $this->criarUsuarioComPermissoes($tenant, ['modules.tarefas.view']);
        $pasta    = $this->criarPasta($tenant, $dono);
        $meta     = $this->criarMeta($pasta, $dono, [$dono]);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $soModulo, $tenant);
        $this->duplicar($client, (int) $meta->getId());

        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->contarMetasDaPasta((int) $pasta->getId()));
    }

    #[TestDox('irmão: o mesmo perfil COM resources.pasta.view duplica')]
    public function testComVerAPastaDuplica(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $dono   = $this->criarUsuario($tenant, 'Dona da pasta');
        $comVer = $this->criarUsuarioComPermissoes($tenant, ['modules.tarefas.view', 'resources.pasta.view']);
        $pasta  = $this->criarPasta($tenant, $dono);
        $meta   = $this->criarMeta($pasta, $dono, [$dono]);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $comVer, $tenant);
        $this->duplicar($client, (int) $meta->getId());

        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->contarMetasDaPasta((int) $pasta->getId()));
    }

    #[TestDox('CSRF inválido → 403, nenhuma cópia')]
    public function testCsrfInvalidoRecusa(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'Ana Autora');
        $pasta  = $this->criarPasta($tenant, $autor);
        $meta   = $this->criarMeta($pasta, $autor, [$autor]);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);
        // O token de OUTRA ação da mesma meta não serve.
        $this->duplicar($client, (int) $meta->getId(), true, 'TOKEN_excluir_tarefa_' . $meta->getId());

        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->contarMetasDaPasta((int) $pasta->getId()));
    }

    #[TestDox('pasta excluída (lápide): a recusa da lápide em JSON (XHR) e, sem JS, a volta para a pasta; nenhuma cópia')]
    public function testLapideRecusa(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'Ana Autora');
        $pasta  = $this->criarPasta($tenant, $autor, true);
        $meta   = $this->criarMeta($pasta, $autor, [$autor]);
        $pastaId = (int) $pasta->getId();
        $metaId  = (int) $meta->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);
        $this->em()->clear();

        $this->duplicar($client, $metaId);
        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('somente para leitura', (string) ($this->json($client)['mensagem'] ?? ''));

        $this->duplicar($client, $metaId, false);
        self::assertResponseRedirects('/pasta/' . $pastaId);

        self::assertSame(1, $this->contarMetasDaPasta($pastaId));
    }

    // =========================================================================
    // Responsáveis aptos, prioridade exata e o caminho sem JS
    // =========================================================================

    private function suspender(User $user, Tenant $tenant): void
    {
        $this->em()->getConnection()->executeStatement(
            'UPDATE user_tenant SET is_active = false WHERE user_id = ? AND tenant_id = ?',
            [$user->getId(), $tenant->getId()],
        );
    }

    #[TestDox('responsável suspenso no escritório fica fora da cópia (a Nova meta não o aceitaria); continua na original')]
    public function testSuspensoFicaForaDaCopia(): void
    {
        $client   = static::createClient();
        $tenant   = $this->criarTenant();
        $autor    = $this->criarUsuario($tenant, 'Ana Autora');
        $suspenso = $this->criarUsuario($tenant, 'Carla Suspensa');
        $pasta    = $this->criarPasta($tenant, $autor);
        $original = $this->criarMeta($pasta, $autor, [$autor, $suspenso]);
        $this->suspender($suspenso, $tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);
        $this->duplicar($client, (int) $original->getId());

        self::assertResponseIsSuccessful();
        $copiaId = (int) $this->json($client)['copia'];
        $ids = fn (int $metaId): array => array_map('intval', $this->em()->getConnection()->fetchFirstColumn(
            'SELECT user_id FROM tarefa_responsaveis WHERE tarefa_id = ? ORDER BY user_id',
            [$metaId],
        ));
        self::assertSame([(int) $autor->getId()], $ids($copiaId));
        self::assertCount(2, $ids((int) $original->getId()), 'a original não muda');
    }

    #[TestDox('nenhum responsável apto: recusa (409 por XHR; sem JS, o aviso na aba Metas) e nenhuma cópia')]
    public function testTodosInaptosRecusa(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $tenant   = $this->criarTenant();
        $autor    = $this->criarUsuario($tenant, 'Ana Autora');
        $suspenso = $this->criarUsuario($tenant, 'Carla Suspensa');
        $pasta    = $this->criarPasta($tenant, $autor);
        $original = $this->criarMeta($pasta, $autor, [$suspenso]);
        $this->suspender($suspenso, $tenant);
        $pastaId = (int) $pasta->getId();
        $metaId  = (int) $original->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $this->duplicar($client, $metaId);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('Nenhum responsável desta meta pode receber metas agora. Crie pela Nova meta.', $this->json($client)['mensagem'] ?? null);

        $this->duplicar($client, $metaId, false);
        self::assertResponseRedirects('/pasta/' . $pastaId . '#tarefas');
        $client->followRedirect();
        self::assertStringContainsString('Nenhum responsável desta meta pode receber metas agora. Crie pela Nova meta.', (string) $client->getResponse()->getContent());

        self::assertSame(1, $this->contarMetasDaPasta($pastaId));
    }

    #[TestDox('prioridade exata: a original Normal numa pasta de prioridade Alta gera cópia Normal (a criação comum herdaria)')]
    public function testPrioridadeNormalNaPastaAltaFicaNormal(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'Ana Autora');
        $pasta  = $this->criarPasta($tenant, $autor);
        $pasta->setPrioridade(PrioridadePasta::Prioridade);
        $original = $this->criarMeta($pasta, $autor, [$autor]);
        $original->setPrioridade(PrioridadeDaMeta::Normal);
        $this->em()->flush();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);
        $this->duplicar($client, (int) $original->getId());

        self::assertResponseIsSuccessful();
        $prioridade = $this->em()->getConnection()->fetchOne('SELECT prioridade FROM tarefa WHERE id = ?', [(int) $this->json($client)['copia']]);
        self::assertSame(PrioridadeDaMeta::Normal->value, $prioridade);
    }

    #[TestDox('a cópia herda a caixa / etapa da original, mesmo quando o título levaria a outra (Protocolar daria Protocolo)')]
    public function testCopiaHerdaAEtapaDaOriginal(): void
    {
        $client   = static::createClient();
        $tenant   = $this->criarTenant();
        $autor    = $this->criarUsuario($tenant, 'Ana Autora');
        $pasta    = $this->criarPasta($tenant, $autor);
        $original = $this->criarMeta($pasta, $autor, [$autor]);
        $original->setEtapa(EtapaDaPasta::Encerramento);
        $this->em()->flush();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);
        $this->duplicar($client, (int) $original->getId());

        self::assertResponseIsSuccessful();
        $etapa = $this->em()->getConnection()->fetchOne('SELECT etapa FROM tarefa WHERE id = ?', [(int) $this->json($client)['copia']]);
        self::assertSame(EtapaDaPasta::Encerramento->value, $etapa);
    }

    #[TestDox('sem JS: sem VER a pasta e CSRF inválido dão o 403 HTML padrão (não JSON), sem cópia')]
    public function testSemXhrRecusasSaoHtml(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $tenant   = $this->criarTenant();
        $dono     = $this->criarUsuario($tenant, 'Dona da pasta');
        $soModulo = $this->criarUsuarioComPermissoes($tenant, ['modules.tarefas.view']);
        $pasta    = $this->criarPasta($tenant, $dono);
        $meta     = $this->criarMeta($pasta, $dono, [$dono]);
        $metaId   = (int) $meta->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $soModulo, $tenant);
        $this->duplicar($client, $metaId, false);
        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('application/json', (string) $client->getResponse()->headers->get('Content-Type'));

        $this->logarComTenant($client, $dono, $tenant);
        $this->duplicar($client, $metaId, false, 'token_invalido');
        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('application/json', (string) $client->getResponse()->headers->get('Content-Type'));

        self::assertSame(1, $this->contarMetasDaPasta((int) $pasta->getId()));
    }
}
