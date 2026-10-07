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
use App\Tarefa\Controller\MetaNaListaController;
use App\Tarefa\Service\ListaDeMetasDaPastaEmJson;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * PMC-031 — "Marcar como concluída" e "Reabrir meta" na aba Metas da pasta sem recarregar.
 *
 * Com `X-Requested-With: XMLHttpRequest`, `tarefa_concluir` e `tarefa_reabrir` respondem JSON
 * `{sucesso, mudou, mensagem, meta, estado, contagens, html}`, em que `html` é a aba Metas
 * renderizada de novo pelo servidor (o pasta-metas.js só troca os pedaços). A guarda e o CSRF
 * são os de sempre: aqui se prova cada um no caminho XHR — token inválido, sem o módulo, sem
 * acesso à pasta (reabrir), outro escritório (404, com a irmã do próprio escritório funcionando
 * no mesmo teste) e a pasta excluída (lápide: recusa em JSON). Sem XHR, o POST + redirect segue
 * coberto por `PastaMetasListaTelaTest` e `MetaNaListaControllerTest`.
 *
 * "Desfazer" manda o formulário oposto da linha NOVA: o teste de ida e volta usa o token REAL
 * da sessão tirado do HTML devolvido, provando que a linha trocada continua utilizável.
 */
#[CoversClass(TarefaController::class)]
#[CoversClass(MetaNaListaController::class)]
#[CoversClass(ListaDeMetasDaPastaEmJson::class)]
#[Group('tarefa')]
final class AlternarMetaPorXhrTest extends JusPrimeWebTestCase
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
        $tenant->setName('Tenant PMC-031 ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    /** Usuário comum; com `$papelDeSistema` o PermissionChecker libera (sem super admin). */
    private function criarUsuario(Tenant $tenant, string $nome, bool $papelDeSistema = true): User
    {
        $em     = $this->em();
        // Depois de um pedido o EntityManager foi zerado (services_resetter): religa o escritório.
        $tenant = $em->contains($tenant) ? $tenant : $em->getReference(Tenant::class, $tenant->getId());
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('pmc031_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Papel PMC-031 ' . uniqid());
        $role->setIsSystem($papelDeSistema);
        $em->persist($role);

        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $em->persist($ut);
        $em->flush();

        return $user;
    }

    /** @param list<string> $codigos */
    private function criarUsuarioComPermissoes(Tenant $tenant, string $nome, array $codigos): User
    {
        $em     = $this->em();
        // Depois de um pedido o EntityManager foi zerado (services_resetter): religa o escritório.
        $tenant = $em->contains($tenant) ? $tenant : $em->getReference(Tenant::class, $tenant->getId());
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Papel PMC-031 restrito ' . uniqid());
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
        $user->setEmail('pmc031_perm_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $em->persist($ut);
        $em->flush();

        return $user;
    }

    private function criarPasta(Tenant $tenant, User $criador): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('PMC031-' . uniqid());
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($criador);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function criarMeta(Pasta $pasta, User $autor, string $status = Tarefa::STATUS_PENDENTE, string $titulo = 'Protocolar recurso', string $prazo = '+5 days midnight'): Tarefa
    {
        $meta = new Tarefa();
        $meta->setTitulo($titulo);
        $meta->setDescricao('...');
        $meta->setPrazo(new \DateTimeImmutable($prazo));
        $meta->setStatus($status);
        if ($status === Tarefa::STATUS_CONCLUIDA) {
            $meta->setDataConclusao(new \DateTimeImmutable('-1 day'));
        }
        $meta->setPasta($pasta);
        $meta->setTenant($pasta->getTenant());
        $meta->setCriadoPor($autor);
        $meta->addResponsavel($autor);
        $this->em()->persist($meta);
        $pasta->getTarefas()->add($meta);
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

    /** @return array{KernelBrowser, Tenant, User, Pasta} */
    private function cenario(bool $csrfPrevisivel = true): array
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'Ana Autora');
        $pasta  = $this->criarPasta($tenant, $autor);

        $client->disableReboot();
        if ($csrfPrevisivel) {
            $this->instalarCsrfPrevisivel();
        }
        $this->logarComTenant($client, $autor, $tenant);

        return [$client, $tenant, $autor, $pasta];
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        $dados = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($dados);

        return $dados;
    }

    /** @param array<string, mixed> $dados */
    private function aba(array $dados): Crawler
    {
        self::assertIsString($dados['html']);

        return new Crawler('<!DOCTYPE html><html><body>' . $dados['html'] . '</body></html>');
    }

    // =========================================================================
    // Concluir
    // =========================================================================

    #[TestDox('concluir por XHR: grava, responde JSON com "Meta concluída", contagens e a aba renderizada de novo (linha concluída com o form de reabrir); sem flash')]
    public function testConcluirPorXhr(): void
    {
        [$client, , $autor, $pasta] = $this->cenario();
        $id     = (int) $this->criarMeta($pasta, $autor)->getId();
        $outra  = (int) $this->criarMeta($pasta, $autor, Tarefa::STATUS_PENDENTE, 'Juntar procuração', '-2 days midnight')->getId();

        $client->xmlHttpRequest('POST', '/tarefas/' . $id . '/concluir', ['_token' => 'TOKEN_concluir_tarefa_' . $id]);
        $dados = $this->json($client);

        self::assertTrue($dados['sucesso']);
        self::assertTrue($dados['mudou']);
        self::assertSame('Meta concluída', $dados['mensagem']);
        self::assertSame($id, $dados['meta']);
        self::assertSame('concluida', $dados['estado']);
        self::assertSame(['abertas' => 1, 'atrasadas' => 1, 'concluidas' => 1, 'total' => 2], $dados['contagens']);

        $aba   = $this->aba($dados);
        $linha = $aba->filter('[data-ps-metas] .ps-metas-lista > article.ps-meta[data-meta-id="' . $id . '"]');
        self::assertCount(1, $linha);
        self::assertSame('concluida', $linha->attr('data-meta-estado'));
        self::assertCount(1, $linha->filter('.ps-meta-acoes form.ps-meta-reabrir[action="/tarefas/' . $id . '/reabrir"]'), 'o Desfazer manda este form');
        // O html vem do render do próprio pedido, com o gerador real de token: só se exige que exista.
        // A ida e volta com o token real é o testDesfazerComTokenReal.
        self::assertNotSame('', (string) $linha->filter('form.ps-meta-reabrir input[name="_token"]')->attr('value'));
        self::assertCount(0, $linha->filter('form.ps-meta-concluir'));
        self::assertSame('atrasada', $aba->filter('article.ps-meta[data-meta-id="' . $outra . '"]')->attr('data-meta-estado'), 'as outras linhas vêm junto, com o próprio estado');

        // Os pedaços que o pasta-metas.js troca vêm todos na resposta.
        self::assertSame(
            ['Abertas 1', 'Atrasadas 1', 'Concluídas 1', 'Todas 2'],
            $aba->filter('[data-ps-metas] button.ps-metas-filtro')->each(static fn (Crawler $b) => trim($b->filter('span')->eq(0)->text()) . ' ' . trim($b->filter('span.ps-metas-filtro-n')->text())),
        );
        self::assertSame('1/2', trim($aba->filter('.ps-trilho .ps-metas-progresso-num')->text()));
        self::assertCount(1, $aba->filter('#psMetaRelatorio .ps-mrel-meta[data-meta-rel="' . $id . '"] form.ps-mrel-alternar-form[action="/tarefas/' . $id . '/reabrir"]'));

        $meta = $this->metaNoBanco($id);
        self::assertSame(Tarefa::STATUS_CONCLUIDA, $meta->getStatus());
        self::assertNotNull($meta->getDataConclusao());
        self::assertSame([], $client->getRequest()->getSession()->getFlashBag()->peek('success'), 'no XHR o aviso é do JS: nada de flash para a próxima página');
    }

    #[TestDox('concluir por XHR uma meta já concluída: sucesso sem mudança (mudou=false), nada a desfazer, data de conclusão preservada')]
    public function testConcluirJaConcluidaPorXhr(): void
    {
        [$client, , $autor, $pasta] = $this->cenario();
        $meta  = $this->criarMeta($pasta, $autor, Tarefa::STATUS_CONCLUIDA);
        $id    = (int) $meta->getId();
        $antes = $meta->getDataConclusao();

        $client->xmlHttpRequest('POST', '/tarefas/' . $id . '/concluir', ['_token' => 'TOKEN_concluir_tarefa_' . $id]);
        $dados = $this->json($client);

        self::assertTrue($dados['sucesso']);
        self::assertFalse($dados['mudou']);
        self::assertSame('A meta já estava concluída', $dados['mensagem']);
        self::assertNotNull($antes);
        self::assertSame($antes->format('Y-m-d H:i:s'), $this->metaNoBanco($id)->getDataConclusao()?->format('Y-m-d H:i:s'));
    }

    #[TestDox('concluir por XHR com token inválido → 403 e nada muda')]
    public function testConcluirPorXhrCsrf(): void
    {
        [$client, , $autor, $pasta] = $this->cenario();
        $id = (int) $this->criarMeta($pasta, $autor)->getId();

        $client->xmlHttpRequest('POST', '/tarefas/' . $id . '/concluir', ['_token' => 'invalido']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(Tarefa::STATUS_PENDENTE, $this->metaNoBanco($id)->getStatus());
    }

    #[TestDox('concluir por XHR sem o módulo Tarefas → 403 e nada muda')]
    public function testConcluirPorXhrSemModulo(): void
    {
        [$client, $tenant, $autor, $pasta] = $this->cenario();
        $id      = (int) $this->criarMeta($pasta, $autor)->getId();
        $semNada = $this->criarUsuario($tenant, 'Sem módulo', false);

        $this->logarComTenant($client, $semNada, $tenant);
        $client->xmlHttpRequest('POST', '/tarefas/' . $id . '/concluir', ['_token' => 'TOKEN_concluir_tarefa_' . $id]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(Tarefa::STATUS_PENDENTE, $this->metaNoBanco($id)->getStatus());
    }

    #[TestDox('concluir por XHR meta de OUTRO escritório → 404 sem JSON da aba; a irmã do próprio escritório conclui')]
    public function testConcluirPorXhrCrossTenant(): void
    {
        [$client, , $autor, $pasta] = $this->cenario();
        $idA = (int) $this->criarMeta($pasta, $autor, Tarefa::STATUS_PENDENTE, 'Meta secreta de A')->getId();

        $tenantB = $this->criarTenant();
        $userB   = $this->criarUsuario($tenantB, 'Usuário B');
        $idB     = (int) $this->criarMeta($this->criarPasta($tenantB, $userB), $userB)->getId();

        $this->logarComTenant($client, $userB, $tenantB);
        $client->xmlHttpRequest('POST', '/tarefas/' . $idB . '/concluir', ['_token' => 'TOKEN_concluir_tarefa_' . $idB]);
        $dadosB = $this->json($client);
        self::assertStringNotContainsString('Meta secreta de A', $dadosB['html'], 'a aba devolvida é só da pasta da meta de B');

        $client->xmlHttpRequest('POST', '/tarefas/' . $idA . '/concluir', ['_token' => 'TOKEN_concluir_tarefa_' . $idA]);
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('Meta secreta de A', (string) $client->getResponse()->getContent());

        self::assertSame(Tarefa::STATUS_PENDENTE, $this->metaNoBanco($idA)->getStatus());
        self::assertSame(Tarefa::STATUS_CONCLUIDA, $this->metaNoBanco($idB)->getStatus());
    }

    #[TestDox('concluir por XHR na pasta excluída (lápide) → 403 em JSON com a mensagem da lápide; nada muda')]
    public function testConcluirPorXhrNaLapide(): void
    {
        [$client, , $autor, $pasta] = $this->cenario();
        $id = (int) $this->criarMeta($pasta, $autor)->getId();
        $pasta->marcarExcluida($autor, new \DateTimeImmutable());
        $this->em()->flush();

        $client->xmlHttpRequest('POST', '/tarefas/' . $id . '/concluir', ['_token' => 'TOKEN_concluir_tarefa_' . $id]);

        self::assertResponseStatusCodeSame(403);
        $dados = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('excluída', (string) $dados['mensagem'], 'o JS mostra esta mensagem no aviso');
        self::assertSame(Tarefa::STATUS_PENDENTE, $this->metaNoBanco($id)->getStatus());
    }

    // =========================================================================
    // Reabrir
    // =========================================================================

    #[TestDox('reabrir por XHR: volta a pendente, responde "Meta reaberta" com a linha aberta e o form de concluir; sem flash')]
    public function testReabrirPorXhr(): void
    {
        [$client, , $autor, $pasta] = $this->cenario();
        $id = (int) $this->criarMeta($pasta, $autor, Tarefa::STATUS_CONCLUIDA)->getId();

        $client->xmlHttpRequest('POST', '/tarefas/' . $id . '/reabrir', ['_token' => 'TOKEN_reabrir_tarefa_' . $id]);
        $dados = $this->json($client);

        self::assertTrue($dados['sucesso']);
        self::assertTrue($dados['mudou']);
        self::assertSame('Meta reaberta', $dados['mensagem']);
        self::assertSame('aberta', $dados['estado']);
        self::assertSame(['abertas' => 1, 'atrasadas' => 0, 'concluidas' => 0, 'total' => 1], $dados['contagens']);

        $linha = $this->aba($dados)->filter('[data-ps-metas] .ps-metas-lista > article.ps-meta[data-meta-id="' . $id . '"]');
        self::assertSame('aberta', $linha->attr('data-meta-estado'));
        self::assertCount(1, $linha->filter('.ps-meta-acoes form.ps-meta-concluir[action="/tarefas/' . $id . '/concluir"]'));

        $meta = $this->metaNoBanco($id);
        self::assertSame(Tarefa::STATUS_PENDENTE, $meta->getStatus());
        self::assertNull($meta->getDataConclusao());
        self::assertSame([], $client->getRequest()->getSession()->getFlashBag()->peek('success'));
    }

    #[TestDox('reabrir por XHR meta que não está concluída: sucesso sem mudança (mudou=false)')]
    public function testReabrirAbertaPorXhr(): void
    {
        [$client, , $autor, $pasta] = $this->cenario();
        $id = (int) $this->criarMeta($pasta, $autor, Tarefa::STATUS_EM_REVISAO)->getId();

        $client->xmlHttpRequest('POST', '/tarefas/' . $id . '/reabrir', ['_token' => 'TOKEN_reabrir_tarefa_' . $id]);
        $dados = $this->json($client);

        self::assertFalse($dados['mudou']);
        self::assertSame('A meta não estava concluída', $dados['mensagem']);
        self::assertSame(Tarefa::STATUS_EM_REVISAO, $this->metaNoBanco($id)->getStatus());
    }

    #[TestDox('reabrir por XHR: token inválido, sem o módulo e sem acesso à PASTA → 403; nada muda')]
    public function testReabrirPorXhrRecusas(): void
    {
        [$client, $tenant, $autor, $pasta] = $this->cenario();
        $id = (int) $this->criarMeta($pasta, $autor, Tarefa::STATUS_CONCLUIDA)->getId();

        $client->xmlHttpRequest('POST', '/tarefas/' . $id . '/reabrir', ['_token' => 'invalido']);
        self::assertResponseStatusCodeSame(403);

        $this->logarComTenant($client, $this->criarUsuario($tenant, 'Sem módulo', false), $tenant);
        $client->xmlHttpRequest('POST', '/tarefas/' . $id . '/reabrir', ['_token' => 'TOKEN_reabrir_tarefa_' . $id]);
        self::assertResponseStatusCodeSame(403);

        $this->logarComTenant($client, $this->criarUsuarioComPermissoes($tenant, 'Só Módulo', ['modules.tarefas.view']), $tenant);
        $client->xmlHttpRequest('POST', '/tarefas/' . $id . '/reabrir', ['_token' => 'TOKEN_reabrir_tarefa_' . $id]);
        self::assertResponseStatusCodeSame(403);

        self::assertSame(Tarefa::STATUS_CONCLUIDA, $this->metaNoBanco($id)->getStatus());
    }

    #[TestDox('reabrir por XHR meta de OUTRO escritório → 404; a irmã do próprio escritório reabre')]
    public function testReabrirPorXhrCrossTenant(): void
    {
        [$client, , $autor, $pasta] = $this->cenario();
        $idA = (int) $this->criarMeta($pasta, $autor, Tarefa::STATUS_CONCLUIDA)->getId();

        $tenantB = $this->criarTenant();
        $userB   = $this->criarUsuario($tenantB, 'Usuário B');
        $idB     = (int) $this->criarMeta($this->criarPasta($tenantB, $userB), $userB, Tarefa::STATUS_CONCLUIDA)->getId();

        $this->logarComTenant($client, $userB, $tenantB);
        $client->xmlHttpRequest('POST', '/tarefas/' . $idB . '/reabrir', ['_token' => 'TOKEN_reabrir_tarefa_' . $idB]);
        self::assertTrue($this->json($client)['mudou']);

        $client->xmlHttpRequest('POST', '/tarefas/' . $idA . '/reabrir', ['_token' => 'TOKEN_reabrir_tarefa_' . $idA]);
        self::assertResponseStatusCodeSame(404);

        self::assertSame(Tarefa::STATUS_CONCLUIDA, $this->metaNoBanco($idA)->getStatus());
        self::assertSame(Tarefa::STATUS_PENDENTE, $this->metaNoBanco($idB)->getStatus());
    }

    #[TestDox('reabrir por XHR na pasta excluída (lápide) → 403 em JSON; continua concluída')]
    public function testReabrirPorXhrNaLapide(): void
    {
        [$client, , $autor, $pasta] = $this->cenario();
        $id = (int) $this->criarMeta($pasta, $autor, Tarefa::STATUS_CONCLUIDA)->getId();
        $pasta->marcarExcluida($autor, new \DateTimeImmutable());
        $this->em()->flush();

        $client->xmlHttpRequest('POST', '/tarefas/' . $id . '/reabrir', ['_token' => 'TOKEN_reabrir_tarefa_' . $id]);

        self::assertResponseStatusCodeSame(403);
        $dados = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('excluída', (string) $dados['mensagem']);
        self::assertSame(Tarefa::STATUS_CONCLUIDA, $this->metaNoBanco($id)->getStatus());
    }

    // =========================================================================
    // Desfazer (ida e volta com token REAL)
    // =========================================================================

    #[TestDox('Desfazer: concluir pelo form da página e, com o token REAL tirado do HTML devolvido, reabrir — a meta volta a aberta')]
    public function testDesfazerComTokenReal(): void
    {
        [$client, , $autor, $pasta] = $this->cenario(false);
        $id = (int) $this->criarMeta($pasta, $autor)->getId();

        $pagina = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();
        $token = (string) $pagina->filter('#psMetaMenu' . $id . ' form.ps-meta-concluir input[name="_token"]')->attr('value');
        self::assertNotSame('', $token);

        $client->xmlHttpRequest('POST', '/tarefas/' . $id . '/concluir', ['_token' => $token]);
        $dados = $this->json($client);
        self::assertTrue($dados['mudou']);
        self::assertSame('concluida', $dados['estado']);

        $oposto = $this->aba($dados)->filter('#psMetaMenu' . $id . ' form.ps-meta-reabrir');
        self::assertCount(1, $oposto);
        $client->xmlHttpRequest('POST', (string) $oposto->attr('action'), ['_token' => (string) $oposto->filter('input[name="_token"]')->attr('value')]);
        $volta = $this->json($client);

        self::assertTrue($volta['mudou']);
        self::assertSame('aberta', $volta['estado']);
        self::assertSame(Tarefa::STATUS_PENDENTE, $this->metaNoBanco($id)->getStatus());
        self::assertNull($this->metaNoBanco($id)->getDataConclusao());
    }

    // =========================================================================
    // PMC-061 — Desfazer do reabrir devolve a data de conclusão ORIGINAL
    // =========================================================================

    /** Meta concluída no dia X (`$concluidaEm`), criada antes dele — como na vida real. */
    private function metaConcluidaEm(Pasta $pasta, User $autor, string $concluidaEm, string $titulo = 'Protocolar recurso'): int
    {
        $id = (int) $this->criarMeta($pasta, $autor, Tarefa::STATUS_CONCLUIDA, $titulo)->getId();
        $this->em()->getConnection()->executeStatement(
            'UPDATE tarefa SET data_criacao = :criada, data_conclusao = :concluida WHERE id = :id',
            ['criada' => (new \DateTimeImmutable('-20 days'))->format('Y-m-d H:i:s'), 'concluida' => $concluidaEm, 'id' => $id],
        );
        // A meta criada acima segue no identity map com as datas antigas; o pedido a releria de lá.
        $this->em()->clear();

        return $id;
    }

    #[TestDox('PMC-061: concluída no dia X, reabrir e Desfazer → volta concluída com dataConclusao = X (não agora); o reabrir informa X; a restauração entra no audit_log')]
    public function testDesfazerReabrirDevolveADataOriginal(): void
    {
        [$client, $tenant, $autor, $pasta] = $this->cenario();
        $x  = (new \DateTimeImmutable('-3 days'))->setTime(14, 30)->format('Y-m-d H:i:s');
        $id = $this->metaConcluidaEm($pasta, $autor, $x);

        $client->xmlHttpRequest('POST', '/tarefas/' . $id . '/reabrir', ['_token' => 'TOKEN_reabrir_tarefa_' . $id]);
        $reaberta = $this->json($client);
        self::assertTrue($reaberta['mudou']);
        self::assertIsString($reaberta['dataConclusaoAnterior']);
        self::assertSame($x, (new \DateTimeImmutable($reaberta['dataConclusaoAnterior']))->format('Y-m-d H:i:s'), 'o reabrir informa a data que apagou');
        self::assertNull($this->metaNoBanco($id)->getDataConclusao());

        $client->xmlHttpRequest('POST', '/tarefas/' . $id . '/concluir', ['_token' => 'TOKEN_concluir_tarefa_' . $id, 'desfazer' => '1']);
        $desfeita = $this->json($client);

        self::assertTrue($desfeita['mudou']);
        self::assertSame('concluida', $desfeita['estado']);
        $meta = $this->metaNoBanco($id);
        self::assertSame(Tarefa::STATUS_CONCLUIDA, $meta->getStatus());
        self::assertSame($x, $meta->getDataConclusao()?->format('Y-m-d H:i:s'), 'a data é a ORIGINAL, não a de agora');

        $auditadas = (int) $this->em()->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE entity_class = ? AND entity_id = ? AND action = 'update' AND route = 'tarefa_concluir' AND tenant_id = ?",
            [Tarefa::class, (string) $id, $tenant->getId()],
        );
        self::assertSame(1, $auditadas, 'a restauração fica na trilha de auditoria');

        // Uma vez só: um segundo Desfazer (depois de reabrir por outro caminho) não acha a lembrança.
        $this->em()->getConnection()->executeStatement("UPDATE tarefa SET status = 'pendente', data_conclusao = NULL WHERE id = :id", ['id' => $id]);
        $client->xmlHttpRequest('POST', '/tarefas/' . $id . '/concluir', ['_token' => 'TOKEN_concluir_tarefa_' . $id, 'desfazer' => '1']);
        self::assertResponseStatusCodeSame(409);
    }

    #[TestDox('PMC-061: Desfazer sem reabrir antes (ou de OUTRA meta) → 409 com a mensagem; a meta continua aberta e NÃO ganha a data de agora')]
    public function testDesfazerSemLembrancaRecusa(): void
    {
        [$client, , $autor, $pasta] = $this->cenario();
        $aberta = (int) $this->criarMeta($pasta, $autor, Tarefa::STATUS_PENDENTE, 'Juntar procuração')->getId();
        $outra  = $this->metaConcluidaEm($pasta, $autor, (new \DateTimeImmutable('-2 days'))->format('Y-m-d H:i:s'), 'Outra meta');

        // A lembrança é da OUTRA meta: não serve para esta.
        $client->xmlHttpRequest('POST', '/tarefas/' . $outra . '/reabrir', ['_token' => 'TOKEN_reabrir_tarefa_' . $outra]);
        self::assertTrue($this->json($client)['mudou']);

        $client->xmlHttpRequest('POST', '/tarefas/' . $aberta . '/concluir', ['_token' => 'TOKEN_concluir_tarefa_' . $aberta, 'desfazer' => '1']);

        self::assertResponseStatusCodeSame(409);
        $dados = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertFalse($dados['sucesso']);
        self::assertStringContainsString('Não foi possível desfazer', (string) $dados['mensagem']);
        $meta = $this->metaNoBanco($aberta);
        self::assertSame(Tarefa::STATUS_PENDENTE, $meta->getStatus());
        self::assertNull($meta->getDataConclusao());
    }

    #[TestDox('PMC-061: concluir normal depois de reabrir grava a data de agora e encerra o Desfazer pendente')]
    public function testConcluirNormalEncerraODesfazer(): void
    {
        [$client, , $autor, $pasta] = $this->cenario();
        $x  = (new \DateTimeImmutable('-3 days'))->format('Y-m-d H:i:s');
        $id = $this->metaConcluidaEm($pasta, $autor, $x);

        $client->xmlHttpRequest('POST', '/tarefas/' . $id . '/reabrir', ['_token' => 'TOKEN_reabrir_tarefa_' . $id]);
        $this->json($client);

        $antes = new \DateTimeImmutable('-1 second');
        $client->xmlHttpRequest('POST', '/tarefas/' . $id . '/concluir', ['_token' => 'TOKEN_concluir_tarefa_' . $id]);
        self::assertTrue($this->json($client)['mudou']);
        self::assertGreaterThanOrEqual($antes, $this->metaNoBanco($id)->getDataConclusao(), 'sem a flag é o concluir de sempre');

        $this->em()->getConnection()->executeStatement("UPDATE tarefa SET status = 'pendente', data_conclusao = NULL WHERE id = :id", ['id' => $id]);
        $client->xmlHttpRequest('POST', '/tarefas/' . $id . '/concluir', ['_token' => 'TOKEN_concluir_tarefa_' . $id, 'desfazer' => '1']);
        self::assertResponseStatusCodeSame(409);
    }

    // =========================================================================
    // Contrato com o pasta-metas.js
    // =========================================================================

    #[TestDox('contrato: o pasta-metas.js intercepta os forms .ps-meta-concluir, .ps-meta-reabrir e .ps-mrel-alternar-form, manda X-Requested-With e o aviso tem "Desfazer"')]
    public function testContratoComOJs(): void
    {
        $js = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/public/js/pasta-metas.js');

        self::assertStringContainsString("'form.ps-meta-concluir, form.ps-meta-reabrir, form.ps-mrel-alternar-form'", $js);
        self::assertStringContainsString("'X-Requested-With': 'XMLHttpRequest'", $js);
        self::assertStringContainsString("'Desfazer'", $js);
        self::assertStringContainsString('ps-metas-aviso', $js);
        // PMC-061: o Desfazer de um reabrir pede ao servidor a data original; nenhuma data sai do JS.
        self::assertStringContainsString("if (ehDesfazer && form.matches('form.ps-meta-concluir')) { corpo.append('desfazer', '1'); }", $js);

        $twig = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/templates/pasta/_metas.html.twig');
        self::assertStringContainsString('.ps-metas-aviso {', $twig, 'o estilo do aviso mora no <style> da aba');
    }
}
