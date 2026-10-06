<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Controller\TarefaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * L5 da Trilha B — aba Metas da pasta no desenho "02 - EXPEDIENTES 1.2.3":
 * filtros Abertas · Atrasadas · Concluídas · Todas com a contagem, numeração local,
 * "concluída no prazo dd/mm/aaaa" / "concluída com N dia(s) de atraso", concluir
 * pelo menu ⋮ da linha (o MESMO `tarefa_concluir`, mesmo CSRF, mesma guarda) e,
 * no modal "Nova meta", os atalhos de prazo e o aviso de título repetido.
 *
 * O filtro e o aviso rodam no navegador (pasta-metas.js); aqui se prova o que eles
 * leem: o estado de cada linha, o título e o prazo em `data-*`, e que o prazo e os
 * responsáveis continuam OPCIONAIS (tornar obrigatório é decisão pendente com o dono).
 */
#[CoversClass(PastaController::class)]
#[CoversClass(TarefaController::class)]
#[Group('pasta')]
final class PastaMetasListaTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private function abrir(object $client, Pasta $pasta): object
    {
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** Pasta com autor: a guarda do `tarefa_concluir` exige criador/responsável vinculado ao escritório. */
    private function criarPastaDe(Tenant $tenant, User $criador): Pasta
    {
        $pasta = $this->criarPasta($tenant);
        $pasta->setCriadoPor($criador);
        $this->em()->flush();

        return $pasta;
    }

    private function criarMeta(
        Pasta $pasta,
        User $user,
        string $titulo,
        string $status,
        ?string $prazo,
        ?string $conclusao = null,
    ): Tarefa {
        $meta = new Tarefa();
        $meta->setTitulo($titulo);
        $meta->setDescricao('...');
        if ($prazo !== null) {
            $meta->setPrazo(new \DateTimeImmutable($prazo));
        }
        if ($conclusao !== null) {
            $meta->setDataConclusao(new \DateTimeImmutable($conclusao));
        }
        $meta->setPasta($pasta);
        $meta->setTenant($pasta->getTenant());
        $meta->setCriadoPor($user);
        $meta->addResponsavel($user);
        $meta->setStatus($status);
        $this->em()->persist($meta);
        $pasta->getTarefas()->add($meta);
        $this->em()->flush();

        return $meta;
    }

    /** Usuário comum (papel de sistema) de outro escritório — sem o bypass de super admin. */
    private function criarUsuarioDoEscritorio(Tenant $tenant): User
    {
        $em     = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('metas_b_' . uniqid() . '@test.com');
        $user->setFullName('Usuário B');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Papel B ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $em->persist($ut);
        $em->flush();

        return $user;
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

    private function statusNoBanco(int $id): array
    {
        // Leitura de CONFERÊNCIA: depois de uma requisição como outro escritório o TenantFilter
        // fica no tenant dele e o find() devolveria null — o que se quer ver é o banco.
        $filtros = $this->em()->getFilters();
        if ($filtros->isEnabled('tenant')) {
            $filtros->disable('tenant');
        }
        $this->em()->clear();
        $meta = $this->em()->find(Tarefa::class, $id);
        self::assertNotNull($meta, 'a meta sumiu do banco');

        return [$meta->getStatus(), $meta->getDataConclusao()];
    }

    // =========================================================================
    // Filtros e numeração
    // =========================================================================

    #[TestDox('filtros do desenho no cabeçalho, com a contagem real; "Abertas" começa ativo; cada linha diz o estado que o filtro lê')]
    public function testFiltrosComContagemEEstadoDasLinhas(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaDe($tenant, $user);
        $atrasada  = $this->criarMeta($pasta, $user, 'Protocolar recurso', Tarefa::STATUS_PENDENTE, '-3 days midnight');
        $aberta    = $this->criarMeta($pasta, $user, 'Juntar procuração', Tarefa::STATUS_PENDENTE, '+5 days midnight');
        $revisao   = $this->criarMeta($pasta, $user, 'Revisar petição', Tarefa::STATUS_EM_REVISAO, null);
        $concluida = $this->criarMeta($pasta, $user, 'Ligar para o cliente', Tarefa::STATUS_CONCLUIDA, '2026-09-10', '2026-09-09 10:00');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $filtros = $crawler->filter('#tarefas > .ps-grade > .ps-metas > .ps-card-cab--painel > .ps-metas-filtros > button.ps-metas-filtro[data-ps-metas-filtro]');
        self::assertSame(['abertas', 'atrasadas', 'concluidas', 'todas'], $filtros->each(static fn ($b) => $b->attr('data-ps-metas-filtro')));
        self::assertSame(
            ['Abertas 3', 'Atrasadas 1', 'Concluídas 1', 'Todas 4'],
            $filtros->each(static fn ($b) => trim($b->filter('span')->eq(0)->text()) . ' ' . trim($b->filter('span.ps-metas-filtro-n')->text())),
            'Abertas = tudo que não está concluído (inclui atrasada e em revisão)',
        );
        self::assertSame(['true', 'false', 'false', 'false'], $filtros->each(static fn ($b) => $b->attr('aria-pressed')), 'o desenho abre em "Abertas"');
        self::assertStringContainsString('is-ativo', (string) $filtros->first()->attr('class'));

        $estado = static function (Tarefa $m) use ($crawler): ?string {
            return $crawler->filter('.ps-metas-lista > article.ps-meta[data-meta-id="' . $m->getId() . '"]')->attr('data-meta-estado');
        };
        self::assertSame('atrasada', $estado($atrasada));
        self::assertSame('aberta', $estado($aberta));
        self::assertSame('aberta', $estado($revisao), 'em revisão é aberta');
        self::assertSame('concluida', $estado($concluida));

        $vazio = $crawler->filter('#tarefas .ps-metas > .ps-metas-filtro-vazio');
        self::assertCount(1, $vazio, 'há o vazio do FILTRO, escondido até o filtro esvaziar a lista');
        self::assertNotNull($vazio->attr('hidden'));
        self::assertSame('Nenhuma meta neste filtro', trim($vazio->filter('.ps-vazio-titulo')->text()));
        self::assertCount(1, $crawler->filter('script[src="/js/pasta-metas.js"]'), 'quem filtra é o pasta-metas.js');
    }

    #[TestDox('numeração local "1." "2." pela ordem de criação na pasta — o id global do sistema não aparece')]
    public function testNumeracaoLocal(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaDe($tenant, $user);
        $primeira = $this->criarMeta($pasta, $user, 'Primeira meta', Tarefa::STATUS_CONCLUIDA, null);
        $segunda  = $this->criarMeta($pasta, $user, 'Segunda meta', Tarefa::STATUS_PENDENTE, null);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $linhas = $crawler->filter('.ps-metas-lista > article.ps-meta');
        self::assertSame([(string) $primeira->getId(), (string) $segunda->getId()], $linhas->each(static fn ($l) => $l->attr('data-meta-id')), 'a lista segue a ordem de criação');
        self::assertSame(['1.', '2.'], $linhas->each(static fn ($l) => trim($l->filter('.ps-meta-abrir > .ps-meta-corpo > .ps-meta-titulo > .ps-meta-num')->text())));
        self::assertSame('Meta 2 desta pasta', $linhas->eq(1)->filter('.ps-meta-num')->attr('title'));
        self::assertSame('Segunda meta', trim($linhas->eq(1)->filter('.ps-meta-titulo > .ps-meta-titulo-tx')->text()));
        self::assertCount(0, $crawler->filter('#tarefas .ps-meta-titulo')->reduce(static fn ($n) => str_contains($n->text(), 'Nº ')), 'sem número global');
    }

    // =========================================================================
    // Prazo da meta concluída
    // =========================================================================

    #[TestDox('concluída: "concluída no prazo dd/mm/aaaa", "concluída com N dia(s) de atraso" e, sem data de conclusão, "prazo dd/mm/aaaa"')]
    public function testRotuloDoPrazoConcluido(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaDe($tenant, $user);
        $noPrazo    = $this->criarMeta($pasta, $user, 'No prazo', Tarefa::STATUS_CONCLUIDA, '2026-09-10', '2026-09-10 18:30');
        $umDia      = $this->criarMeta($pasta, $user, 'Um dia', Tarefa::STATUS_CONCLUIDA, '2026-09-10', '2026-09-11 08:00');
        $atrasada   = $this->criarMeta($pasta, $user, 'Atrasada', Tarefa::STATUS_CONCLUIDA, '2026-09-10', '2026-09-22 08:00');
        $semData    = $this->criarMeta($pasta, $user, 'Sem data', Tarefa::STATUS_CONCLUIDA, '2026-09-10');
        $semPrazo   = $this->criarMeta($pasta, $user, 'Sem prazo', Tarefa::STATUS_CONCLUIDA, null, '2026-09-22 08:00');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $prazo = static function (Tarefa $m) use ($crawler): object {
            return $crawler->filter('.ps-metas-lista > article.ps-meta[data-meta-id="' . $m->getId() . '"] .ps-meta-linha > .ps-meta-prazo');
        };
        self::assertSame('concluída no prazo 10/09/2026', trim($prazo($noPrazo)->text()), 'no DIA do prazo é no prazo (hora não conta)');
        self::assertSame('concluída com 1 dia de atraso', trim($prazo($umDia)->text()));
        self::assertSame('concluída com 12 dias de atraso', trim($prazo($atrasada)->text()));
        self::assertSame('prazo 10/09/2026', trim($prazo($semData)->text()), 'sem data de conclusão o sistema não afirma "no prazo"');
        self::assertCount(0, $prazo($semPrazo), 'sem prazo, sem rótulo de prazo');
        self::assertCount(0, $crawler->filter('.ps-meta--concluida .ps-meta-prazo--atraso'), 'o vermelho de atraso é só de meta ABERTA');
    }

    // =========================================================================
    // Concluir na lista
    // =========================================================================

    #[TestDox('meta aberta: o ⋮ traz "Marcar como concluída", um POST ao tarefa_concluir de sempre com o token concluir_tarefa_<id>; concluída não tem ⋮')]
    public function testMenuConcluirApontaParaOEndpointReal(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaDe($tenant, $user);
        $aberta    = $this->criarMeta($pasta, $user, 'Aberta', Tarefa::STATUS_PENDENTE, '+5 days midnight');
        $concluida = $this->criarMeta($pasta, $user, 'Concluída', Tarefa::STATUS_CONCLUIDA, null);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $linha = $crawler->filter('.ps-metas-lista > article.ps-meta[data-meta-id="' . $aberta->getId() . '"]');
        $botao = $linha->filter('article > .ps-meta-acoes > button.ps-meta-menu-btn');
        self::assertCount(1, $botao, 'o ⋮ é filho da linha, FORA da âncora (botão dentro de link não é HTML válido)');
        self::assertSame('psMetaMenu' . $aberta->getId(), $botao->attr('data-ps-pop'), 'aberto pelo popovers() do pasta-show.js');
        self::assertCount(0, $linha->filter('a.ps-meta-abrir button, a.ps-meta-abrir form'));

        $form = $linha->filter('.ps-meta-acoes > #psMetaMenu' . $aberta->getId() . '.ps-pop > form.ps-meta-concluir');
        self::assertCount(1, $form);
        self::assertSame('post', strtolower((string) $form->attr('method')));
        self::assertSame('/tarefas/' . $aberta->getId() . '/concluir', $form->attr('action'));
        self::assertNotSame('', (string) $form->filter('input[type="hidden"][name="_token"]')->attr('value'));
        self::assertSame('Marcar como concluída', trim($form->filter('button[type="submit"].ps-pop-item')->text()));

        self::assertCount(0, $crawler->filter('article.ps-meta[data-meta-id="' . $concluida->getId() . '"] .ps-meta-acoes'), 'reabrir é da onda 2: sem item real, sem ⋮');
    }

    #[TestDox('submeter o form da lista conclui a meta (token REAL da sessão), volta para a aba Metas e a linha passa a "concluída no prazo"')]
    public function testConcluirPelaLista(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaDe($tenant, $user);
        $meta            = $this->criarMeta($pasta, $user, 'Concluir pela lista', Tarefa::STATUS_PENDENTE, '+5 days midnight');
        $metaId          = (int) $meta->getId();
        $prazo           = $meta->getPrazo()->format('d/m/Y');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $client->submit($crawler->filter('#psMetaMenu' . $metaId . ' form.ps-meta-concluir')->form());
        self::assertResponseRedirects('/pasta/' . $pasta->getId() . '#tarefas');

        [$status, $conclusao] = $this->statusNoBanco($metaId);
        self::assertSame(Tarefa::STATUS_CONCLUIDA, $status);
        self::assertNotNull($conclusao);

        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();
        $linha = $crawler->filter('.ps-metas-lista > article.ps-meta[data-meta-id="' . $metaId . '"]');
        self::assertSame('concluida', $linha->attr('data-meta-estado'));
        self::assertSame('concluída no prazo ' . $prazo, trim($linha->filter('.ps-meta-linha > .ps-meta-prazo')->text()));
        self::assertCount(0, $linha->filter('.ps-meta-acoes'));
    }

    #[TestDox('POST com token inválido não conclui (o CSRF do endpoint continua valendo)')]
    public function testTokenInvalidoNaoConclui(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaDe($tenant, $user);
        $metaId          = (int) $this->criarMeta($pasta, $user, 'Token ruim', Tarefa::STATUS_PENDENTE, null)->getId();

        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', '/tarefas/' . $metaId . '/concluir', ['_token' => 'invalido']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(Tarefa::STATUS_PENDENTE, $this->statusNoBanco($metaId)[0]);
    }

    #[TestDox('controle: com o token previsível, o dono da pasta conclui — é o que torna a recusa cross-tenant uma prova da GUARDA')]
    public function testTokenPrevisivelValeParaODono(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaDe($tenant, $user);
        $metaId          = (int) $this->criarMeta($pasta, $user, 'Controle', Tarefa::STATUS_PENDENTE, null)->getId();

        $this->instalarCsrfPrevisivel();
        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', '/tarefas/' . $metaId . '/concluir', ['_token' => 'TOKEN_concluir_tarefa_' . $metaId]);

        self::assertResponseRedirects();
        self::assertSame(Tarefa::STATUS_CONCLUIDA, $this->statusNoBanco($metaId)[0]);
    }

    #[TestDox('cross-tenant: usuário de OUTRO escritório, com token válido, não conclui a meta (403/404) e nada muda')]
    public function testOutroEscritorioNaoConclui(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaDe($tenant, $user);
        $metaId          = (int) $this->criarMeta($pasta, $user, 'Do escritório A', Tarefa::STATUS_PENDENTE, null)->getId();

        $tenantB = $this->criarTenant();
        $userB   = $this->criarUsuarioDoEscritorio($tenantB);

        $this->instalarCsrfPrevisivel();
        $this->logarComTenant($client, $userB, $tenantB);
        $client->request('POST', '/tarefas/' . $metaId . '/concluir', ['_token' => 'TOKEN_concluir_tarefa_' . $metaId]);

        self::assertContains($client->getResponse()->getStatusCode(), [403, 404]);
        [$status, $conclusao] = $this->statusNoBanco($metaId);
        self::assertSame(Tarefa::STATUS_PENDENTE, $status);
        self::assertNull($conclusao);
    }

    #[TestDox('cross-tenant: a lista da pasta de B não traz linha nem form de concluir de meta de A')]
    public function testListaNaoMisturaEscritorios(): void
    {
        $client          = static::createClient();
        [$userA, $tenantA] = $this->criarAdmin();
        $metaA = $this->criarMeta($this->criarPastaDe($tenantA, $userA), $userA, 'Meta de A', Tarefa::STATUS_PENDENTE, null);

        [$userB, $tenantB] = $this->criarAdmin();
        $pastaB = $this->criarPastaDe($tenantB, $userB);
        $metaB  = $this->criarMeta($pastaB, $userB, 'Meta de B', Tarefa::STATUS_PENDENTE, null);

        $this->logarComTenant($client, $userB, $tenantB);
        $crawler = $this->abrir($client, $pastaB);

        self::assertSame([(string) $metaB->getId()], $crawler->filter('.ps-metas-lista > article.ps-meta')->each(static fn ($l) => $l->attr('data-meta-id')));
        self::assertCount(0, $crawler->filter('form[action="/tarefas/' . $metaA->getId() . '/concluir"]'));
        self::assertCount(0, $crawler->filter('[data-meta-titulo="Meta de A"]'), 'o aviso de título repetido só compara com as metas DESTA pasta');
    }

    // =========================================================================
    // Modal "Nova meta"
    // =========================================================================

    #[TestDox('Nova meta: atalhos Hoje · Amanhã · +5 úteis · +15 úteis ao lado do campo, texto do dia e aviso de repetido escondido; prazo e responsáveis seguem opcionais')]
    public function testModalNovaMeta(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaDe($tenant, $user);
        $meta            = $this->criarMeta($pasta, $user, 'Protocolar "recurso" especial', Tarefa::STATUS_PENDENTE, '2026-10-20');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $prazo = $crawler->filter('#modalCriarTarefa #formCriarTarefa .ps-nm-prazo');
        self::assertCount(1, $prazo);
        self::assertCount(1, $prazo->filter('.ps-nm-prazo > .ps-nm-prazo-linha > input#tarefaPrazo[type="date"][name="prazo"]'), 'o campo de sempre');
        self::assertSame(
            ['hoje' => 'Hoje', 'amanha' => 'Amanhã', 'uteis5' => '+5 úteis', 'uteis15' => '+15 úteis'],
            array_combine(
                $prazo->filter('.ps-nm-prazo-linha > button[type="button"][data-ps-prazo-atalho]')->each(static fn ($b) => $b->attr('data-ps-prazo-atalho')),
                $prazo->filter('.ps-nm-prazo-linha > button[type="button"][data-ps-prazo-atalho]')->each(static fn ($b) => trim($b->text())),
            ),
        );
        self::assertSame('Escolha a data limite.', trim($prazo->filter('.ps-nm-prazo > #tarefaPrazoInfo')->text()));

        $dup = $prazo->filter('.ps-nm-prazo > #tarefaTituloRepetido');
        self::assertCount(1, $dup);
        self::assertNotNull($dup->attr('hidden'), 'só aparece quando o título se parece com uma meta aberta');
        self::assertSame('Abrir meta existente', trim($dup->filter('button[type="button"].ps-nm-dup-abrir')->text()));

        self::assertNull($crawler->filter('#tarefaPrazo')->attr('required'), 'prazo continua opcional');
        self::assertNull($crawler->filter('#tarefaResponsavel')->attr('required'), 'responsáveis continuam opcionais');
        self::assertNotNull($crawler->filter('#tarefaTitulo')->attr('required'));

        // O que o aviso lê: título e prazo da meta em data-*, com o título ESCAPADO.
        $linha = $crawler->filter('article.ps-meta[data-meta-id="' . $meta->getId() . '"]');
        self::assertSame('Protocolar "recurso" especial', $linha->attr('data-meta-titulo'));
        self::assertSame('20/10/2026', $linha->attr('data-meta-prazo'));
    }
}
