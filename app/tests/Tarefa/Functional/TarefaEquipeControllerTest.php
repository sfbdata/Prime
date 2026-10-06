<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Cargo;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tarefa\Controller\TarefaEquipeController;
use App\Tarefa\UseCase\ListarMetasDaEquipeUseCase;
use App\Tests\Dashboard\Functional\DashboardWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Tela "Metas da equipe" (/tarefas/equipe) — destino dos números de metas do Dashboard.
 *
 * Herda a base do Dashboard de propósito: a guarda desta tela é a MESMA (módulo `bi`), e o
 * gestor criado ali é exatamente o perfil que vê o número clicado.
 */
#[CoversClass(TarefaEquipeController::class)]
#[CoversClass(ListarMetasDaEquipeUseCase::class)]
#[Group('dashboard')]
final class TarefaEquipeControllerTest extends DashboardWebTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function criarMeta(
        Tenant $tenant,
        User $responsavel,
        string $titulo,
        string $status = Tarefa::STATUS_PENDENTE,
        ?\DateTimeImmutable $prazo = null,
    ): Tarefa {
        $em = $this->em();

        $pasta = new Pasta();
        $pasta->setNup('EQ-' . uniqid());
        $pasta->setNomeCliente('Cliente Equipe');
        $pasta->setResponsavel($responsavel);
        $pasta->setTenant($tenant);
        $em->persist($pasta);

        $tarefa = new Tarefa();
        $tarefa->setTitulo($titulo);
        $tarefa->setDescricao('Descrição');
        $tarefa->setPasta($pasta);
        $tarefa->setTenant($tenant);
        $tarefa->setStatus($status);
        $tarefa->setPrazo($prazo);
        $tarefa->addResponsavel($responsavel);
        $em->persist($tarefa);
        $em->flush();

        return $tarefa;
    }

    private function envelhecer(Tarefa $tarefa, string $dataCriacao): void
    {
        $this->em()->getConnection()->executeStatement(
            'UPDATE tarefa SET data_criacao = :d WHERE id = :id',
            ['d' => $dataCriacao, 'id' => $tarefa->getId()],
        );
    }

    private function atribuirCargo(User $user, Tenant $tenant, string $nome): void
    {
        $em    = $this->em();
        $cargo = new Cargo();
        $cargo->setNome($nome);
        $cargo->setTenant($tenant);
        $em->persist($cargo);

        $ut = $em->getRepository(UserTenant::class)->findOneBy(['user' => $user, 'tenant' => $tenant]);
        self::assertNotNull($ut);
        $ut->setCargo($cargo);
        $em->flush();
    }

    /** @return list<string> títulos na ordem da lista */
    private function titulos(Crawler $crawler): array
    {
        return $crawler->filter('[data-equipe-lista] [data-equipe-meta] .mm-linha-titulo')
            ->each(static fn (Crawler $n): string => trim($n->text()));
    }

    private function total(Crawler $crawler): int
    {
        return (int) $crawler->filter('[data-equipe-total]')->attr('data-equipe-total');
    }

    // ── Acesso ──────────────────────────────────────────────────────────────

    #[TestDox('Sem login, /tarefas/equipe redireciona para o login')]
    public function testSemLoginRedireciona(): void
    {
        $client = static::createClient();
        $client->request('GET', '/tarefas/equipe');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('Autenticado sem modules.bi.view recebe 403 (mesma guarda do Dashboard)')]
    public function testSemPermissaoBiRetorna403(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $colab  = $this->criarColaborador($tenant, 'Sem Permissão');
        $this->logarComTenant($client, $colab, $tenant);

        $client->request('GET', '/tarefas/equipe');

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Com modules.bi.view, /tarefas/equipe responde 200 (não é capturada por /tarefas/{id})')]
    public function testComPermissaoRetorna200(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $client->request('GET', '/tarefas/equipe');

        self::assertResponseIsSuccessful();
        self::assertRouteSame('tarefa_equipe');
    }

    // ── Status ──────────────────────────────────────────────────────────────

    #[TestDox('status=todas lista todas as metas do responsável, inclusive concluídas')]
    public function testStatusTodas(): void
    {
        $client          = static::createClient();
        [, $tenant]      = $this->criarGestorLogado($client);
        $fulano          = $this->criarColaborador($tenant, 'Fulano de Tal');
        $this->criarMeta($tenant, $fulano, 'Meta pendente');
        $this->criarMeta($tenant, $fulano, 'Meta concluída', Tarefa::STATUS_CONCLUIDA);
        $this->criarMeta($tenant, $fulano, 'Meta em revisão', Tarefa::STATUS_EM_REVISAO);

        $crawler = $client->request('GET', '/tarefas/equipe', ['status' => 'todas', 'responsavel' => $fulano->getId()]);

        self::assertResponseIsSuccessful();
        self::assertEqualsCanonicalizing(['Meta pendente', 'Meta concluída', 'Meta em revisão'], $this->titulos($crawler));
        self::assertSame(3, $this->total($crawler));
        self::assertSelectorTextContains('[data-equipe-titulo]', 'Metas — Fulano de Tal');
    }

    #[TestDox('status=ativas exclui só as concluídas (em revisão continua ativa, como no Dashboard)')]
    public function testStatusAtivas(): void
    {
        $client     = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $fulano     = $this->criarColaborador($tenant, 'Fulano de Tal');
        $this->criarMeta($tenant, $fulano, 'Meta pendente');
        $this->criarMeta($tenant, $fulano, 'Meta concluída', Tarefa::STATUS_CONCLUIDA);
        $this->criarMeta($tenant, $fulano, 'Meta em revisão', Tarefa::STATUS_EM_REVISAO);

        $crawler = $client->request('GET', '/tarefas/equipe', ['status' => 'ativas', 'responsavel' => $fulano->getId()]);

        self::assertEqualsCanonicalizing(['Meta pendente', 'Meta em revisão'], $this->titulos($crawler));
        self::assertSelectorTextContains('[data-equipe-titulo]', 'Metas ativas — Fulano de Tal');
    }

    #[TestDox('status=vencidas lista ativas com prazo passado e IGNORA o período')]
    public function testStatusVencidasIgnoraPeriodo(): void
    {
        $client     = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $fulano     = $this->criarColaborador($tenant, 'Fulano de Tal');

        $vencidaAntiga = $this->criarMeta($tenant, $fulano, 'Vencida antiga', prazo: new \DateTimeImmutable('-40 days'));
        $this->envelhecer($vencidaAntiga, '2020-01-01 00:00:00');
        $this->criarMeta($tenant, $fulano, 'Vencida recente', prazo: new \DateTimeImmutable('-2 days'));
        $this->criarMeta($tenant, $fulano, 'Concluída vencida', Tarefa::STATUS_CONCLUIDA, new \DateTimeImmutable('-2 days'));
        $this->criarMeta($tenant, $fulano, 'No prazo', prazo: new \DateTimeImmutable('+3 days'));
        $this->criarMeta($tenant, $fulano, 'Sem prazo');

        $hoje    = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $crawler = $client->request('GET', '/tarefas/equipe', [
            'status'      => 'vencidas',
            'responsavel' => $fulano->getId(),
            'data_de'     => $hoje,
            'data_ate'    => $hoje,
        ]);

        self::assertEqualsCanonicalizing(['Vencida antiga', 'Vencida recente'], $this->titulos($crawler));
        $titulo = $crawler->filter('[data-equipe-titulo]')->text();
        self::assertStringContainsString('Metas vencidas — Fulano de Tal', $titulo);
        self::assertStringNotContainsString('·', $titulo, 'Vencidas não têm recorte de período, o título não pode sugerir um.');
    }

    #[TestDox('status=prazo_proximo lista ativas com prazo nos próximos 7 dias, sem período')]
    public function testStatusPrazoProximo(): void
    {
        $client     = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $fulano     = $this->criarColaborador($tenant, 'Fulano de Tal');

        $antiga = $this->criarMeta($tenant, $fulano, 'Vence em 3 dias', prazo: new \DateTimeImmutable('+3 days'));
        $this->envelhecer($antiga, '2020-01-01 00:00:00');
        $this->criarMeta($tenant, $fulano, 'Vence em 20 dias', prazo: new \DateTimeImmutable('+20 days'));
        $this->criarMeta($tenant, $fulano, 'Já venceu', prazo: new \DateTimeImmutable('-1 day'));
        $this->criarMeta($tenant, $fulano, 'Concluída próxima', Tarefa::STATUS_CONCLUIDA, new \DateTimeImmutable('+2 days'));

        $crawler = $client->request('GET', '/tarefas/equipe', ['status' => 'prazo_proximo', 'responsavel' => $fulano->getId()]);

        self::assertSame(['Vence em 3 dias'], $this->titulos($crawler));
        self::assertSelectorTextContains('[data-equipe-titulo]', 'Prazos próximos — Fulano de Tal');
    }

    #[TestDox('Status desconhecido cai em "todas" (tela de leitura não quebra por parâmetro estranho)')]
    public function testStatusDesconhecidoCaiEmTodas(): void
    {
        $client     = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $fulano     = $this->criarColaborador($tenant, 'Fulano de Tal');
        $this->criarMeta($tenant, $fulano, 'Meta concluída', Tarefa::STATUS_CONCLUIDA);

        $crawler = $client->request('GET', '/tarefas/equipe', ['status' => 'xpto', 'responsavel' => $fulano->getId()]);

        self::assertResponseIsSuccessful();
        self::assertSame(['Meta concluída'], $this->titulos($crawler));
    }

    // ── Filtros ─────────────────────────────────────────────────────────────

    #[TestDox('Período (data_de/data_ate por dataCriacao) restringe todas/ativas e aparece no título')]
    public function testPeriodoRestringeEApareceNoTitulo(): void
    {
        $client     = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $fulano     = $this->criarColaborador($tenant, 'Fulano de Tal');
        $this->criarMeta($tenant, $fulano, 'Criada hoje');
        $antiga = $this->criarMeta($tenant, $fulano, 'Criada em 2020');
        $this->envelhecer($antiga, '2020-01-15 10:00:00');

        $crawler = $client->request('GET', '/tarefas/equipe', [
            'status'      => 'todas',
            'responsavel' => $fulano->getId(),
            'data_de'     => '2020-01-01',
            'data_ate'    => '2020-01-31',
        ]);

        self::assertSame(['Criada em 2020'], $this->titulos($crawler));
        self::assertSelectorTextContains('[data-equipe-titulo]', 'Metas — Fulano de Tal · 01/01/2020–31/01/2020');
    }

    #[TestDox('Sem responsável, a lista cobre a equipe toda e cada meta aparece uma vez')]
    public function testSemResponsavelListaEquipeSemDuplicar(): void
    {
        $client     = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $fulano     = $this->criarColaborador($tenant, 'Fulano de Tal');
        $beltrana   = $this->criarColaborador($tenant, 'Beltrana Souza');
        $compartilhada = $this->criarMeta($tenant, $fulano, 'Meta a dois');
        $compartilhada->addResponsavel($beltrana);
        $this->em()->flush();
        $this->criarMeta($tenant, $beltrana, 'Meta da Beltrana');

        $crawler = $client->request('GET', '/tarefas/equipe', ['status' => 'todas']);

        self::assertEqualsCanonicalizing(['Meta a dois', 'Meta da Beltrana'], $this->titulos($crawler));
        self::assertSelectorTextContains('[data-equipe-titulo]', 'Metas — Equipe');
    }

    #[TestDox('cargo restringe a lista aos colaboradores daquele cargo')]
    public function testCargoRestringe(): void
    {
        $client     = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $advogada   = $this->criarColaborador($tenant, 'Advogada Um');
        $estagiario = $this->criarColaborador($tenant, 'Estagiário Dois');
        $this->atribuirCargo($advogada, $tenant, 'Advogado');
        $this->atribuirCargo($estagiario, $tenant, 'Estagiário');
        $this->criarMeta($tenant, $advogada, 'Meta da advogada');
        $this->criarMeta($tenant, $estagiario, 'Meta do estagiário');

        $crawler = $client->request('GET', '/tarefas/equipe', ['status' => 'todas', 'cargo' => 'Advogado']);

        self::assertSame(['Meta da advogada'], $this->titulos($crawler));
        self::assertSelectorTextContains('[data-equipe-titulo]', 'Metas — Advogado');
    }

    #[TestDox('Paginação: 26 metas viram 2 páginas e a página 2 mantém os filtros')]
    public function testPaginacao(): void
    {
        $client     = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $fulano     = $this->criarColaborador($tenant, 'Fulano de Tal');
        for ($i = 1; $i <= ListarMetasDaEquipeUseCase::POR_PAGINA + 1; ++$i) {
            $this->criarMeta($tenant, $fulano, sprintf('Meta %02d', $i));
        }

        $crawler = $client->request('GET', '/tarefas/equipe', ['status' => 'ativas', 'responsavel' => $fulano->getId()]);
        self::assertCount(ListarMetasDaEquipeUseCase::POR_PAGINA, $this->titulos($crawler));
        self::assertSame(ListarMetasDaEquipeUseCase::POR_PAGINA + 1, $this->total($crawler));
        $linkPagina2 = $crawler->filter('[data-equipe-paginacao] a.page-link')->reduce(
            static fn (Crawler $a): bool => trim($a->text()) === '2',
        )->attr('href');
        self::assertStringContainsString('status=ativas', (string) $linkPagina2);
        self::assertStringContainsString('responsavel=' . $fulano->getId(), (string) $linkPagina2);

        $crawler = $client->request('GET', (string) $linkPagina2);
        self::assertCount(1, $this->titulos($crawler));

        // Página além da última mostra a última, não um vazio falso.
        $crawler = $client->request('GET', '/tarefas/equipe', ['status' => 'ativas', 'responsavel' => $fulano->getId(), 'page' => 99]);
        self::assertCount(1, $this->titulos($crawler));
    }

    // ── Isolamento entre escritórios ─────────────────────────────────────────

    #[TestDox('responsavel de OUTRO escritório: 200, lista vazia e o nome dele não aparece')]
    public function testResponsavelDeOutroEscritorioDaListaVazia(): void
    {
        $client     = static::createClient();
        $this->criarGestorLogado($client);

        $outro      = $this->criarTenant();
        $estranho   = $this->criarColaborador($outro, 'Pessoa De Fora');
        $this->criarMeta($outro, $estranho, 'Meta do outro escritório');

        $crawler = $client->request('GET', '/tarefas/equipe', ['status' => 'todas', 'responsavel' => $estranho->getId()]);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->titulos($crawler));
        self::assertSame(1, $crawler->filter('[data-equipe-vazio]')->count());
        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Pessoa De Fora', $body);
        self::assertStringNotContainsString('Meta do outro escritório', $body);
    }

    #[TestDox('Colaborador ativo em DOIS escritórios: só as metas do escritório corrente aparecem')]
    public function testColaboradorEmDoisEscritoriosNaoVazaMetaDoOutro(): void
    {
        $client     = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $fulano     = $this->criarColaborador($tenant, 'Fulano de Tal');

        $outro = $this->criarTenant();
        $this->em()->persist(new UserTenant($fulano, $outro));
        $this->em()->flush();

        $this->criarMeta($tenant, $fulano, 'Meta daqui');
        $this->criarMeta($outro, $fulano, 'Meta de lá');

        // Com o responsável e sem ele: nenhum dos dois caminhos pode trazer a meta de lá.
        $crawler = $client->request('GET', '/tarefas/equipe', ['status' => 'todas', 'responsavel' => $fulano->getId()]);
        self::assertSame(['Meta daqui'], $this->titulos($crawler));

        $crawler = $client->request('GET', '/tarefas/equipe', ['status' => 'todas']);
        self::assertSame(['Meta daqui'], $this->titulos($crawler));
    }
}
