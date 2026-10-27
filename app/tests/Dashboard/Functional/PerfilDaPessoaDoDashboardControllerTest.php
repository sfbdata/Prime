<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\PerfilDaPessoaDoDashboardController;
use App\Dashboard\DTO\ObterPerfilDaPessoaInput;
use App\Dashboard\UseCase\ObterPerfilDaPessoaUseCase;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Análise individual da pessoa (dc 01, botão da linha): GET /dashboard/inteligencia/pessoa/{id} devolve o
 * perfil por regras fixas (e audita a visualização); POST .../marca guarda "Marcar como analisado" e o
 * retorno por usuário. Só o Master ou um primário ativo abre (403); pessoa de outro escritório dá 404.
 */
#[CoversClass(PerfilDaPessoaDoDashboardController::class)]
#[CoversClass(ObterPerfilDaPessoaUseCase::class)]
#[Group('dashboard')]
final class PerfilDaPessoaDoDashboardControllerTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const PERMISSOES_GESTOR = ['modules.expediente.view', 'resources.pasta.view', 'modules.bi.view'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCliente();
    }

    /**
     * Ana: 2 metas criadas agora, uma concluída e uma pendente já vencida (prazo de ontem).
     *
     * @return array{primario: User, tenant: Tenant, gestor: User, ana: User, pasta: Pasta}
     */
    private function cenario(): array
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $gestor = $this->criarColaborador($tenant, 'Gestor Secundário', self::PERMISSOES_GESTOR);
        $ana    = $this->criarColaborador($tenant, 'Ana Equipe');
        $pasta  = $this->criarPasta($tenant);
        $this->novaMeta($tenant, $primario, $ana, $pasta, Tarefa::STATUS_CONCLUIDA, '+5 days');
        $this->novaMeta($tenant, $primario, $ana, $pasta, Tarefa::STATUS_PENDENTE, '-1 day');

        return ['primario' => $primario, 'tenant' => $tenant, 'gestor' => $gestor, 'ana' => $ana, 'pasta' => $pasta];
    }

    private function novaMeta(Tenant $tenant, User $criador, User $responsavel, Pasta $pasta, string $status, string $prazo): void
    {
        $meta = new Tarefa();
        $meta->setTitulo('Meta da Ana');
        $meta->setDescricao('Descrição');
        $meta->setPasta($pasta);
        $meta->setTenant($tenant);
        $meta->setCriadoPor($criador);
        $meta->addResponsavel($responsavel);
        $meta->setPrazo(new \DateTimeImmutable($prazo));
        $meta->setStatus($status);
        $this->em()->persist($meta);
        $this->em()->flush();
    }

    private function perfil(User $pessoa): Crawler
    {
        return $this->client->request('GET', '/dashboard/inteligencia/pessoa/' . $pessoa->getId());
    }

    private function auditorias(Tenant $tenant, string $acao): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_log WHERE tenant_id = :t AND action = :a', ['t' => $tenant->getId(), 'a' => $acao]);
    }

    private function marcar(User $pessoa, string $acao, string $token = 'TOKEN_ajax'): void
    {
        $this->client->request('POST', '/dashboard/inteligencia/pessoa/' . $pessoa->getId() . '/marca', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], json_encode(['acao' => $acao], JSON_THROW_ON_ERROR));
    }

    private function marcasNoBanco(Tenant $tenant): int
    {
        return (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM preferencia_usuario WHERE tenant_id = :t AND chave = 'dashboard.ia_pessoas'", ['t' => $tenant->getId()]);
    }

    /** @return array<string, string> chave da métrica => texto */
    private function metricas(Crawler $c): array
    {
        $mapa = [];
        $c->filter('.db-pf-metricas > .db-pf-metrica')->each(static function (Crawler $m) use (&$mapa): void {
            $mapa[(string) $m->attr('data-metrica')] = trim(preg_replace('/\s+/u', ' ', $m->filter('.db-pf-metrica-v')->text()) ?? '');
        });

        return $mapa;
    }

    #[TestDox('Primário abre o perfil de Ana: os seis números, link de tarefas, só "Observado", sem classificação inventada; audita a visualização')]
    public function testPrimarioAbreEAudita(): void
    {
        $c = $this->cenario();
        $this->entrar($c['primario'], $c['tenant']);

        $perfil = $this->perfil($c['ana']);

        self::assertResponseIsSuccessful();
        self::assertSame('Ana Equipe', trim($perfil->filter('.db-pf-nome')->text()));
        self::assertSame('Análise de desempenho · Ana Equipe', $perfil->filter('[data-pf]')->attr('data-titulo'));
        self::assertStringNotContainsString('Prazos cumpridos', $perfil->filter('.db-pf-metricas')->text());
        self::assertSame(['recebidas' => '2', 'concluidas' => '1', 'pendentes' => '1', 'atrasadas' => '1', 'prazos' => '0', 'taxa' => '50%'], $this->metricas($perfil));
        $href = (string) $perfil->filter('.db-pf-acoes a.db-pf-btn')->attr('href');
        self::assertStringContainsString('/tarefas/equipe?', $href);
        self::assertStringContainsString('responsavel=' . $c['ana']->getId(), $href);
        self::assertSame(['Observado'], array_values(array_unique($perfil->filter('.db-pf-tag')->each(static fn (Crawler $t): string => trim($t->text())))));
        $html = (string) $this->client->getResponse()->getContent();
        foreach (['Correlação', 'Hipótese', 'Redistribuir', 'Plano de ação'] as $naoDeve) {
            self::assertStringNotContainsString($naoDeve, $html, $naoDeve);
        }
        self::assertSame(1, $this->auditorias($c['tenant'], 'ia_pessoa'));
        $log = $this->em()->getConnection()->fetchAssociative("SELECT entity_id, actor_user_id, changes FROM audit_log WHERE action = 'ia_pessoa' AND tenant_id = :t", ['t' => $c['tenant']->getId()]);
        self::assertSame((string) $c['ana']->getId(), $log['entity_id']);
        self::assertSame($c['primario']->getId(), (int) $log['actor_user_id']);
        self::assertSame(['evento' => 'visualizou', 'pessoa_id' => $c['ana']->getId()], json_decode((string) $log['changes'], true));
    }

    #[TestDox('Quem tem o Dashboard mas não é primário recebe 403 no perfil e na marca; nada é auditado nem gravado')]
    public function testNaoPrimario403(): void
    {
        $c = $this->cenario();
        $this->entrar($c['gestor'], $c['tenant']);

        $this->perfil($c['ana']);
        self::assertResponseStatusCodeSame(403);
        $this->marcar($c['ana'], 'analisado');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->auditorias($c['tenant'], 'ia_pessoa'));
        self::assertSame(0, $this->auditorias($c['tenant'], 'ia_marca'));
        self::assertSame(0, $this->marcasNoBanco($c['tenant']));
    }

    #[TestDox('Pessoa de outro escritório dá 404 no perfil e na marca; nada é auditado nem gravado')]
    public function testPessoaDeOutroEscritorio404(): void
    {
        $c = $this->cenario();
        [, $outro] = $this->criarEscritorio();
        $deFora = $this->criarColaborador($outro, 'Pessoa de Fora');
        $this->entrar($c['primario'], $c['tenant']);

        $this->perfil($deFora);
        self::assertResponseStatusCodeSame(404);
        $this->marcar($deFora, 'analisado');
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->auditorias($c['tenant'], 'ia_pessoa'));
        self::assertSame(0, $this->auditorias($c['tenant'], 'ia_marca'));
        self::assertSame(0, $this->marcasNoBanco($c['tenant']));
    }

    #[TestDox('Marcar como analisado e avaliar: grava por usuário, o perfil reaberto mostra a marca, repetir desfaz, e cada marca é auditada')]
    public function testMarcarGravaEPersiste(): void
    {
        $c = $this->cenario();
        $this->entrar($c['primario'], $c['tenant']);

        $this->marcar($c['ana'], 'analisado');
        self::assertResponseIsSuccessful();
        self::assertSame(['pessoa' => $c['ana']->getId(), 'status' => 'analisado', 'feedback' => null], json_decode((string) $this->client->getResponse()->getContent(), true));
        $this->marcar($c['ana'], 'parcial');
        self::assertSame(1, $this->marcasNoBanco($c['tenant']));
        self::assertSame(2, $this->auditorias($c['tenant'], 'ia_marca'));

        $perfil = $this->perfil($c['ana']);
        self::assertSame('Analisado', trim($perfil->filter('[data-pf-status]')->text()));
        self::assertSame('true', $perfil->filter('[data-pf-marcar="analisado"]')->attr('aria-pressed'));
        self::assertSame('true', $perfil->filter('[data-pf-marcar="parcial"]')->attr('aria-pressed'));
        self::assertSame('false', $perfil->filter('[data-pf-marcar="sim"]')->attr('aria-pressed'));

        $this->marcar($c['ana'], 'analisado');
        self::assertNull(json_decode((string) $this->client->getResponse()->getContent(), true)['status']);
    }

    #[TestDox('CSRF inválido dá 403 e ação desconhecida dá 400; nada é gravado')]
    public function testCsrfEAcao(): void
    {
        $c = $this->cenario();
        $this->entrar($c['primario'], $c['tenant']);

        $this->marcar($c['ana'], 'analisado', 'TOKEN_errado');
        self::assertResponseStatusCodeSame(403);
        $this->marcar($c['ana'], 'apagar');
        self::assertResponseStatusCodeSame(400);
        self::assertSame(0, $this->marcasNoBanco($c['tenant']));
        self::assertSame(0, $this->auditorias($c['tenant'], 'ia_marca'));
    }

    #[TestDox('Cadeado: a meta de pasta oculta para quem olha não entra nos números do perfil')]
    public function testCadeadoNaoEntraNosNumeros(): void
    {
        $c = $this->cenario();
        $oculta = $this->criarPasta($c['tenant']);
        $this->novaMeta($c['tenant'], $c['primario'], $c['ana'], $oculta, Tarefa::STATUS_PENDENTE, '-3 days');
        $useCase = static::getContainer()->get(ObterPerfilDaPessoaUseCase::class);
        $pedido  = static fn (array $ocultas): ObterPerfilDaPessoaInput => new ObterPerfilDaPessoaInput($c['tenant'], $c['primario'], (int) $c['ana']->getId(), [], new \DateTimeImmutable(), $ocultas);

        $todos = $useCase->executar($pedido([]));
        $livre = $useCase->executar($pedido([(int) $oculta->getId()]));

        $recebidas = static fn ($p): ?int => $p->metricas[0]->valor;
        $atrasadas = static fn ($p): ?int => $p->metricas[3]->valor;
        self::assertSame([3, 2], [$recebidas($todos), $atrasadas($todos)]);
        self::assertSame([2, 1], [$recebidas($livre), $atrasadas($livre)], 'a meta da pasta oculta some de Recebidas e de Atrasadas');
    }

    #[TestDox('Na tabela, o primário vê o botão da análise de cada pessoa e o gestor vê o botão bloqueado com o texto do desenho')]
    public function testBotaoNaTabela(): void
    {
        $c = $this->cenario();
        $this->entrar($c['primario'], $c['tenant']);
        $tela = $this->client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();
        $botao = $tela->filter('tr[data-db-pessoa="' . $c['ana']->getId() . '"] button[data-pf-abrir]');
        self::assertCount(1, $botao);
        self::assertSame('Análise de desempenho de Ana Equipe', $botao->attr('aria-label'));
        self::assertStringEndsWith('/dashboard/inteligencia/pessoa/' . $c['ana']->getId(), (string) $botao->attr('data-url'));
        self::assertCount(1, $tela->filter('aside.db-ia-painel > [data-ia-individual]'));
        self::assertCount(1, $tela->filter('aside.db-ia-painel button[data-ia-pessoa-voltar]'));

        $this->entrar($c['gestor'], $c['tenant']);
        $tela = $this->client->request('GET', '/dashboard');
        $linha = $tela->filter('tr[data-db-pessoa="' . $c['ana']->getId() . '"]');
        self::assertCount(0, $linha->filter('button[data-pf-abrir]'));
        $bloqueado = $linha->filter('button.db-pf-abrir[disabled]');
        self::assertCount(1, $bloqueado);
        self::assertSame('Seu perfil não pode abrir análises individuais', $bloqueado->attr('title'));
    }
}
