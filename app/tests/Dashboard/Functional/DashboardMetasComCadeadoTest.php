<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\DashboardController;
use App\Dashboard\Controller\HistoricoDoDashboardController;
use App\Dashboard\Entity\DashboardMetaPastas;
use App\Dashboard\Repository\DashboardFotoRepository;
use App\Dashboard\Repository\MetricasExtrasDoDashboardRepository;
use App\Dashboard\UseCase\ObterDadosDashboardUseCase;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PrioridadePasta;
use App\Repository\UserRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Dashboard da equipe com cadeado (spec pasta-cadeado, D1 e D3, "Acompanhamento depois dos merges"): as metas
 * de pasta Oculta para quem olha saem dos cards e das colunas de metas, como da lista /tarefas/equipe a que os
 * números levam. O mesmo vale para as colunas e cards de pastas (a lista do Expediente filtrada não mostra a
 * oculta) e para o histórico do card "Pastas criadas". Quem tem pasta oculta não recebe as setas que comparam com
 * a foto diária do escritório inteiro (a diferença revelaria a meta oculta). O primário e quem tem nível na pasta
 * continuam vendo tudo.
 */
#[CoversClass(DashboardController::class)]
#[CoversClass(ObterDadosDashboardUseCase::class)]
#[CoversClass(MetricasExtrasDoDashboardRepository::class)]
#[CoversClass(HistoricoDoDashboardController::class)]
final class DashboardMetasComCadeadoTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const PERMISSOES_GESTOR = ['modules.expediente.view', 'resources.pasta.view', 'modules.bi.view'];

    private const PERIODO = 'data_de=2026-09-01&data_ate=2026-09-30';
    private const VESPERA = '2026-08-31';

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCliente();
    }

    /**
     * Ana responde por uma meta numa pasta com cadeado e por outra numa pasta livre, as duas vencidas. A gestora
     * fica fora da grade do cadeado (Oculto); o gestor livre tem Editar.
     *
     * @return array{primario: User, tenant: Tenant, gestora: User, gestorLivre: User, ana: User, oculta: Pasta}
     */
    private function cenario(string $statusDasMetas = Tarefa::STATUS_PENDENTE, string $prazo = '-2 days'): array
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $gestora     = $this->criarColaborador($tenant, 'Gestora Oculta', self::PERMISSOES_GESTOR);
        $gestorLivre = $this->criarColaborador($tenant, 'Gestor Livre', self::PERMISSOES_GESTOR);
        $ana         = $this->criarColaborador($tenant, 'Ana Equipe');
        $oculta      = $this->criarPasta($tenant);
        $livre       = $this->criarPasta($tenant);
        // As duas pastas são de Ana, urgentes e criadas por ela: entram em Demandas, Urgentes e Pastas criadas.
        foreach ([$oculta, $livre] as $pasta) {
            $pasta->setResponsavel($ana);
            $pasta->setCriadoPor($ana);
            $pasta->setPrioridade(PrioridadePasta::Urgente);
        }
        foreach ([[$oculta, 'Meta dashboard trancada'], [$livre, 'Meta dashboard livre']] as [$pasta, $titulo]) {
            $meta = new Tarefa();
            $meta->setTitulo($titulo);
            $meta->setDescricao('Descrição');
            $meta->setPasta($pasta);
            $meta->setTenant($tenant);
            $meta->setCriadoPor($primario);
            $meta->addResponsavel($ana);
            $meta->setPrazo(new \DateTimeImmutable($prazo));
            $meta->setStatus($statusDasMetas);
            $this->em()->persist($meta);
        }
        $this->em()->flush();
        $this->trancar($oculta, $primario, [[$ana, NivelDeAcesso::Editar], [$gestorLivre, NivelDeAcesso::Editar]]);

        return ['primario' => $primario, 'tenant' => $tenant, 'gestora' => $gestora, 'gestorLivre' => $gestorLivre, 'ana' => $ana, 'oculta' => $oculta];
    }

    /** @return array{card: string, total: string, ativas: string, vencidas: string, html: string} */
    private function painelVistoPor(User $quem, Tenant $tenant): array
    {
        $this->entrar($quem, $tenant);
        $c = $this->client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();

        return [
            'card'     => (string) $c->filter('.db-stat-num[data-card="metas"]')->attr('data-alvo'),
            'total'    => trim($c->filter('a[title="Abrir: Metas de Ana Equipe"]')->first()->text()),
            'ativas'   => trim($c->filter('a[title="Abrir: Metas ativas de Ana Equipe"]')->first()->text()),
            'vencidas' => trim($c->filter('a[title="Abrir: Metas vencidas de Ana Equipe"]')->first()->text()),
            'html'     => (string) $this->client->getResponse()->getContent(),
        ];
    }

    #[TestDox('gestor Oculto na pasta: o card e as colunas de metas não contam a meta dela, e a lista a que levam não mostra o título')]
    public function testGestorOcultoNaoContaNemVeAMetaDaPastaComCadeado(): void
    {
        $c = $this->cenario();

        $painel = $this->painelVistoPor($c['gestora'], $c['tenant']);

        self::assertSame('1', $painel['card'], 'Metas ativas: só a da pasta livre');
        self::assertSame('1', $painel['total']);
        self::assertSame('1', $painel['ativas']);
        self::assertSame('1', $painel['vencidas']);
        self::assertStringNotContainsString('Meta dashboard trancada', $painel['html']);

        // O número leva a /tarefas/equipe: a lista bate com ele e não mostra o título da meta da pasta oculta.
        $this->client->request('GET', '/tarefas/equipe?status=todas&responsavel=' . $c['ana']->getId());
        self::assertResponseIsSuccessful();
        $lista = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Meta dashboard livre', $lista);
        self::assertStringNotContainsString('Meta dashboard trancada', $lista);
    }

    #[TestDox('o ritmo da meta de pastas no painel Intelligence não aparece para quem tem pasta oculta (nenhum número vaza)')]
    public function testRitmoDaMetaNaoApareceComPastaOculta(): void
    {
        $c = $this->cenario();
        $this->em()->persist(new DashboardMetaPastas($c['tenant'], new \DateTimeImmutable('first day of this month'), 4321, $c['primario']));
        $this->em()->flush();
        $hoje = new \DateTimeImmutable('today');
        $url  = '/dashboard?data_de=' . $hoje->format('Y-m-01') . '&data_ate=' . $hoje->format('Y-m-t');

        foreach (['primario' => $c['primario'], 'gestorLivre' => $c['gestorLivre']] as $quem => $usuario) {
            $this->entrar($usuario, $c['tenant']);
            $crawler = $this->client->request('GET', $url);
            self::assertCount(1, $crawler->filter('.db-ia-meta-ritmo'), $quem . ' vê tudo, o ritmo aparece');
        }

        $this->entrar($c['gestora'], $c['tenant']);
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.db-ia-meta-ritmo'), 'o selo mentiria: realizado sem as ocultas, meta do escritório inteiro');
        self::assertStringNotContainsString('db-ia-gauge', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('de 4321 criadas', (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('o primário e o gestor com nível na pasta continuam contando as duas metas')]
    public function testPrimarioEGestorSemRestricaoContinuamVendo(): void
    {
        $c = $this->cenario();

        foreach (['primario' => $c['primario'], 'gestorLivre' => $c['gestorLivre']] as $quem => $usuario) {
            $painel = $this->painelVistoPor($usuario, $c['tenant']);

            self::assertSame('2', $painel['card'], $quem);
            self::assertSame('2', $painel['total'], $quem);
            self::assertSame('2', $painel['ativas'], $quem);
            self::assertSame('2', $painel['vencidas'], $quem);
        }
    }

    #[TestDox('simular dia não abre brecha: com pasta oculta o ritmo da meta continua fora, simulado ou não')]
    public function testSimularDiaNaoRevelaRitmoComPastaOculta(): void
    {
        $c = $this->cenario();
        $this->em()->persist(new DashboardMetaPastas($c['tenant'], new \DateTimeImmutable('first day of this month'), 4321, $c['primario']));
        $this->em()->flush();
        $hoje = new \DateTimeImmutable('today');
        $dia  = $hoje->setDate((int) $hoje->format('Y'), (int) $hoje->format('n'), (int) $hoje->format('j') === 1 ? 2 : 1);
        $url  = '/dashboard?data_de=' . $hoje->format('Y-m-01') . '&data_ate=' . $hoje->format('Y-m-t') . '&dia_simulado=' . $dia->format('Y-m-d');

        $this->entrar($c['primario'], $c['tenant']);
        self::assertCount(1, $this->client->request('GET', $url)->filter('.db-ia-meta-ritmo'), 'quem vê tudo simula o ritmo');

        $this->entrar($c['gestora'], $c['tenant']);
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.db-ia-meta-ritmo'));
        self::assertStringNotContainsString('de 4321 criadas', (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('colunas extras de metas (em revisão e tempo médio) também deixam de contar a meta da pasta oculta')]
    public function testColunasExtrasDeMetas(): void
    {
        $c       = $this->cenario(Tarefa::STATUS_EM_REVISAO);
        $ocultas = static::getContainer()->get(AcessoAPasta::class)->idsOcultosPara($c['gestora'], (int) $c['tenant']->getId());
        $extras  = static::getContainer()->get(MetricasExtrasDoDashboardRepository::class);
        $anaId   = (int) $c['ana']->getId();

        self::assertSame([(int) $c['oculta']->getId()], $ocultas);
        self::assertSame(1, $extras->contarEmRevisaoPorResponsavel($c['tenant'], [], $ocultas)[$anaId] ?? 0);
        self::assertSame(2, $extras->contarEmRevisaoPorResponsavel($c['tenant'])[$anaId] ?? 0, 'sem o cadeado seriam duas');
        self::assertSame([], static::getContainer()->get(AcessoAPasta::class)->idsOcultosPara($c['primario'], (int) $c['tenant']->getId()));

        foreach ($this->em()->getRepository(Tarefa::class)->findBy(['tenant' => $c['tenant']]) as $meta) {
            $meta->setStatus(Tarefa::STATUS_CONCLUIDA);
            $meta->setDataConclusao(new \DateTimeImmutable());
        }
        $this->em()->flush();

        self::assertSame(1, $extras->tempoDeConclusaoPorResponsavel($c['tenant'], [], $ocultas)[$anaId]['metas'] ?? 0);
        self::assertSame(2, $extras->tempoDeConclusaoPorResponsavel($c['tenant'])[$anaId]['metas'] ?? 0);
    }

    #[TestDox('colunas e cards de pastas: gestor Oculto não conta a pasta com cadeado; o primário e o gestor livre contam')]
    public function testColunasECardsDePastas(): void
    {
        $c = $this->cenario();

        foreach (['gestora' => [$c['gestora'], '1'], 'primario' => [$c['primario'], '2'], 'gestorLivre' => [$c['gestorLivre'], '2']] as $quem => [$usuario, $esperado]) {
            $this->entrar($usuario, $c['tenant']);
            $tela = $this->client->request('GET', '/dashboard');
            self::assertResponseIsSuccessful();

            self::assertSame($esperado, (string) $tela->filter('.db-stat-num[data-card="urgentes"]')->attr('data-alvo'), 'Demandas urgentes, ' . $quem);
            self::assertSame($esperado, trim($tela->filter('a[title="Abrir: Expediente · pastas de Ana Equipe"]')->first()->text()), 'Total demandas, ' . $quem);
            self::assertSame($esperado, trim($tela->filter('a[title="Abrir: Expediente · pastas ativas de Ana Equipe"]')->first()->text()), 'Demandas ativas, ' . $quem);
            // Pastas criadas: a lista vem do controller (sem a segunda busca pela sessão) e o número é o mesmo.
            self::assertSame($esperado, trim($tela->filter('a[title="Abrir: Expediente · pastas criadas por Ana Equipe"]')->first()->text()), 'Pastas criadas, ' . $quem);
        }

        // Extra "Pastas urgentes": a mesma régua.
        $ocultas = static::getContainer()->get(AcessoAPasta::class)->idsOcultosPara($c['gestora'], (int) $c['tenant']->getId());
        $extras  = static::getContainer()->get(MetricasExtrasDoDashboardRepository::class);
        $anaId   = (int) $c['ana']->getId();
        self::assertSame(1, $extras->contarUrgentesPorResponsavel($c['tenant'], [], $ocultas)[$anaId] ?? 0);
        self::assertSame(2, $extras->contarUrgentesPorResponsavel($c['tenant'])[$anaId] ?? 0, 'sem o cadeado seriam duas');
    }

    #[TestDox('histórico do card Pastas criadas: o gestor Oculto não conta a pasta com cadeado; o primário conta')]
    public function testHistoricoDePastasCriadas(): void
    {
        $c   = $this->cenario();
        $ano = (new \DateTimeImmutable())->format('Y');

        foreach (['gestora' => [$c['gestora'], 1], 'primario' => [$c['primario'], 2]] as $quem => [$usuario, $esperado]) {
            $this->entrar($usuario, $c['tenant']);
            $this->client->request('GET', '/dashboard/historico/pastas?responsavel=' . $c['ana']->getId());
            self::assertResponseIsSuccessful();
            $dados = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
            $opcao = array_values(array_filter($dados['opcoes'], static fn (array $o): bool => $o['chave'] === $ano))[0];

            self::assertSame($esperado, array_sum(array_map(static fn (array $b): int => (int) $b['valor'], $opcao['barras'])), $quem);
        }
    }

    #[TestDox('setas da foto diária: o gestor com pasta Oculta não as recebe; o primário recebe')]
    public function testSetasDaFotoDiariaSomemParaQuemTemPastaOculta(): void
    {
        $c = $this->cenario();
        $contagens = [];
        foreach (static::getContainer()->get(UserRepository::class)->findColaboradoresAtivosPorTenant($c['tenant']) as $u) {
            $contagens[(int) $u->getId()] = ['metas_ativas' => 10, 'demandas_ativas' => 4, 'metas_vencidas' => 5, 'prazos_proximos' => 3];
        }
        static::getContainer()->get(DashboardFotoRepository::class)->gravar($c['tenant'], new \DateTimeImmutable(self::VESPERA), $contagens);

        $this->entrar($c['primario'], $c['tenant']);
        $tela = $this->client->xmlHttpRequest('GET', '/dashboard?' . self::PERIODO);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->celulaDaAna($tela, 'metas_vencidas')->filter('.db-seta'), 'o primário tem a seta de Vencidas');
        self::assertCount(1, $this->celulaDaAna($tela, 'prazos')->filter('.db-seta'), 'o primário tem a seta de Prazos');

        $this->entrar($c['gestora'], $c['tenant']);
        $tela = $this->client->xmlHttpRequest('GET', '/dashboard?' . self::PERIODO);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $this->celulaDaAna($tela, 'metas_vencidas')->filter('.db-seta'), 'sem a seta de Vencidas');
        self::assertCount(0, $this->celulaDaAna($tela, 'prazos')->filter('.db-seta'), 'sem a seta de Prazos');
        // Nem a pílula do Total (colunas 3 e 4 do rodapé: Vencidas e Prazos).
        $total = $tela->filter('table > tfoot > tr.db-total-row > td');
        self::assertCount(0, $total->eq(3)->filter('.db-tend'));
        self::assertCount(0, $total->eq(4)->filter('.db-tend'));
        // As setas do período anterior, já filtradas, continuam (Total metas).
        self::assertCount(1, $this->celulaDaAna($tela, 'metas')->filter('.db-seta'));
    }

    #[TestDox('coluna Prazo próximo: a meta da pasta Oculta com prazo dentro da janela não conta para a gestora; conta para o primário')]
    public function testPrazoProximo(): void
    {
        $c = $this->cenario(Tarefa::STATUS_PENDENTE, '+2 days');

        foreach (['gestora' => [$c['gestora'], '1'], 'primario' => [$c['primario'], '2']] as $quem => [$usuario, $esperado]) {
            $this->entrar($usuario, $c['tenant']);
            $tela = $this->client->request('GET', '/dashboard');
            self::assertResponseIsSuccessful();

            self::assertSame($esperado, trim($tela->filter('a[title="Abrir: Metas com prazo próximo de Ana Equipe"]')->first()->text()), $quem);
        }
    }

    #[TestDox('card Meta global batida: concluídas e total sem a meta da pasta Oculta para a gestora; com ela para o primário')]
    public function testMetaGlobal(): void
    {
        $c = $this->cenario();
        // A meta da pasta oculta está concluída; a da livre, pendente.
        $trancada = $this->em()->getRepository(Tarefa::class)->findOneBy(['titulo' => 'Meta dashboard trancada', 'tenant' => $c['tenant']]);
        self::assertNotNull($trancada);
        $trancada->setStatus(Tarefa::STATUS_CONCLUIDA);
        $this->em()->flush();

        foreach (['gestora' => [$c['gestora'], ['0', '1'], '0'], 'primario' => [$c['primario'], ['1', '2'], '50']] as $quem => [$usuario, $legenda, $pct]) {
            $this->entrar($usuario, $c['tenant']);
            $tela = $this->client->request('GET', '/dashboard');
            self::assertResponseIsSuccessful();

            $card = $tela->filter('.db-stat-card--global');
            self::assertSame($legenda, $card->filter('.db-card-legenda-num')->each(static fn (Crawler $n): string => trim($n->text())), 'concluídas de total, ' . $quem);
            self::assertSame($pct, (string) $card->filter('.db-stat-num[data-card="global"]')->attr('data-alvo'), $quem);
        }
    }

    private function celulaDaAna(Crawler $tela, string $coluna): Crawler
    {
        $linha = $tela->filter('table.db-table > tbody > tr')->reduce(
            static fn (Crawler $tr): bool => str_contains($tr->filter('.db-colab-nome')->attr('title') ?? '', 'Ana Equipe'),
        );
        self::assertCount(1, $linha);

        return $linha->filter('tr > td[data-coluna="' . $coluna . '"]');
    }
}
