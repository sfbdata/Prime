<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\DashboardController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Cargo;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tarefa\TarefaFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * Lote 4 do Dashboard, na TELA: opção "Sem cargo", busca recolhida no cabeçalho da
 * tabela, tendência (pílula no Total, seta + balão nas células) e números clicáveis.
 *
 * Arranjo sempre por filho direto (`A > B`): distingue "a pílula está na célula do
 * Total" de "existe uma pílula em algum lugar da página".
 *
 * Cenário de período: fevereiro/2024 (29 dias) → anterior = 03/01 a 31/01/2024
 * (contrato do UseCase, provado em DashboardBuscaCargoTendenciaTest).
 */
#[CoversClass(DashboardController::class)]
#[Group('dashboard')]
final class DashboardLote4TelaTest extends DashboardWebTestCase
{
    use Factories;

    private const PERIODO = 'data_de=2024-02-01&data_ate=2024-02-29';

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** Pasta só para hospedar metas: sem responsável/criador e fora das duas janelas. */
    private function pastaHospedeira(Tenant $tenant): Pasta
    {
        return PastaFactory::createOne([
            'tenant'       => $tenant,
            'dataAbertura' => new \DateTimeImmutable('2023-06-01 10:00'),
        ])->_real();
    }

    /** Meta pendente com `dataCriacao` forçada (a entidade não tem setter: nasce "agora"). */
    private function criarMeta(Pasta $pasta, User $responsavel, string $criadaEm, ?\DateTimeImmutable $prazo = null): void
    {
        $tarefa = TarefaFactory::createOne([
            'pasta'  => $pasta,
            'status' => Tarefa::STATUS_PENDENTE,
            'prazo'  => $prazo,
        ])->_real();
        $tarefa->addResponsavel($responsavel);
        (new \ReflectionProperty(Tarefa::class, 'dataCriacao'))->setValue($tarefa, new \DateTimeImmutable($criadaEm));
        $this->em()->flush();
    }

    private function linhaDe(Crawler $crawler, string $nome): Crawler
    {
        $linha = $crawler->filter('.db-table-card table > tbody > tr')->reduce(
            static fn (Crawler $tr): bool => str_contains($tr->filter('.db-colab-nome')->attr('title') ?? '', $nome),
        );
        self::assertCount(1, $linha, 'Linha de ' . $nome);

        return $linha;
    }

    /** @return array{0: string, 1: array<string, string>} caminho e query do href */
    private function destino(Crawler $link): array
    {
        self::assertCount(1, $link, 'link esperado');
        $partes = parse_url((string) $link->attr('href'));
        parse_str($partes['query'] ?? '', $query);

        return [$partes['path'] ?? '', $query];
    }

    /**
     * Ana: metas 3 (fev: 1 vencida, 1 com prazo em 3 dias, 1 sem prazo) × 2 (jan);
     *      pastas criadas/respondidas 2 (fev) × 3 (jan).
     * Bruno: 1 meta em fev × 0 em jan; nenhuma pasta.
     * Outro escritório: meta vencida de fev e 4 pastas de jan da Ana — não podem contar.
     *
     * @return array{0: User, 1: User, 2: User} gestora, Ana, Bruno
     */
    private function montarCenario(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): array
    {
        [$gestora, $tenant] = $this->criarGestorLogado($client);
        $ana   = $this->criarColaborador($tenant, 'Ana Lima');
        $bruno = $this->criarColaborador($tenant, 'Bruno Melo');

        PastaFactory::createMany(2, ['tenant' => $tenant, 'criadoPor' => $ana, 'responsavel' => $ana, 'dataAbertura' => new \DateTimeImmutable('2024-02-10 10:00')]);
        PastaFactory::createMany(3, ['tenant' => $tenant, 'criadoPor' => $ana, 'responsavel' => $ana, 'dataAbertura' => new \DateTimeImmutable('2024-01-20 10:00')]);

        $hosp = $this->pastaHospedeira($tenant);
        $this->criarMeta($hosp, $ana, '2024-02-05 09:00', new \DateTimeImmutable('2024-02-20 18:00'));
        $this->criarMeta($hosp, $ana, '2024-02-06 09:00', new \DateTimeImmutable('+3 days'));
        $this->criarMeta($hosp, $ana, '2024-02-07 09:00');
        $this->criarMeta($hosp, $ana, '2024-01-15 09:00');
        $this->criarMeta($hosp, $ana, '2024-01-16 09:00');
        $this->criarMeta($hosp, $bruno, '2024-02-08 09:00');

        $outro = $this->criarTenant();
        $this->criarMeta($this->pastaHospedeira($outro), $ana, '2024-02-09 09:00', new \DateTimeImmutable('2024-02-15 18:00'));
        PastaFactory::createMany(4, ['tenant' => $outro, 'criadoPor' => $ana, 'responsavel' => $ana, 'dataAbertura' => new \DateTimeImmutable('2024-01-20 10:00')]);

        return [$gestora, $ana, $bruno];
    }

    // ── Sem cargo ─────────────────────────────────────────────────────────

    #[TestDox('"Sem cargo" entra por último no select de cargo quando há colaborador sem cargo')]
    public function testOpcaoSemCargoQuandoExiste(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client); // a gestora não tem cargo

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $opcoes = $crawler->filter('form[data-filtro-form] select[name="cargo"] > option');
        self::assertSame('__sem__', $opcoes->last()->attr('value'));
        self::assertSame('Sem cargo', trim($opcoes->last()->text()));
    }

    #[TestDox('"Sem cargo" não aparece quando todo colaborador ativo tem cargo')]
    public function testSemOpcaoSemCargoQuandoTodosTemCargo(): void
    {
        $client             = static::createClient();
        [$gestora, $tenant] = $this->criarGestorLogado($client);

        $cargo = new Cargo();
        $cargo->setNome('Advogado(a)');
        $cargo->setTenant($tenant);
        $this->em()->persist($cargo);
        $ut = $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $gestora, 'tenant' => $tenant]);
        $ut->setCargo($cargo);
        $this->em()->flush();

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('select[name="cargo"] > option[value="Advogado(a)"]'), 'a irmã prova que o select montou');
        self::assertCount(0, $crawler->filter('select[name="cargo"] > option[value="__sem__"]'));
    }

    #[TestDox('Com cargo=__sem__, a opção fica marcada e o cabeçalho do PDF diz "Sem cargo"')]
    public function testSemCargoSelecionadoNoSelectENoPdf(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $crawler = $client->request('GET', '/dashboard?cargo=__sem__');

        self::assertResponseIsSuccessful();
        self::assertNotNull($crawler->filter('select[name="cargo"] > option[value="__sem__"]')->attr('selected'), 'opção marcada');
        self::assertSame('Todos os responsáveis · Sem cargo', trim($crawler->filter('.db-print-cabecalho .db-print-facetas')->text()));
    }

    // ── Busca ─────────────────────────────────────────────────────────────

    #[TestDox('Busca recolhida no cabeçalho da tabela; o campo que vai na query fica no form')]
    public function testBuscaNoCabecalhoDaTabela(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $busca = $crawler->filter('.db-table-card > .db-table-header > .db-busca');
        self::assertCount(1, $busca, 'A busca é filha direta do cabeçalho "Desempenho"');
        self::assertStringNotContainsString('is-aberta', (string) $busca->attr('class'), 'Sem termo, só a lupa');
        self::assertCount(1, $busca->filter('.db-busca > button.db-busca-lupa.js-db-busca-abrir[aria-label="Buscar colaborador"]'));
        $visivel = $busca->filter('.db-busca > input.db-busca-input.js-db-busca');
        self::assertCount(1, $visivel);
        self::assertNull($visivel->attr('name'), 'O campo visível não tem name: não pode ir duas vezes na query');
        self::assertSame('-1', $visivel->attr('tabindex'), 'Recolhida, fora do Tab');

        $real = $crawler->filter('[data-filtro-root] > .db-filtro-wrap > form[data-filtro-form] input.js-filtro-busca[name="busca"]');
        self::assertCount(1, $real, 'O motor lê o campo de dentro do form (FormData, chip, Limpar)');
        self::assertCount(0, $crawler->filter('[data-filtro-resultado] input[name="busca"]'));
    }

    #[TestDox('Com ?busca=, a busca já abre preenchida, no form e no fragmento do XHR')]
    public function testBuscaPreenchidaAbreAberta(): void
    {
        $client     = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $this->criarColaborador($tenant, 'Ana Lima');

        $crawler = $client->request('GET', '/dashboard?busca=ana');
        self::assertResponseIsSuccessful();
        $busca = $crawler->filter('.db-table-card > .db-table-header > .db-busca.is-aberta');
        self::assertCount(1, $busca);
        self::assertSame('ana', $busca->filter('.db-busca > input.js-db-busca')->attr('value'));
        self::assertSame('0', $busca->filter('.db-busca > input.js-db-busca')->attr('tabindex'));
        self::assertSame('ana', $crawler->filter('form[data-filtro-form] input.js-filtro-busca')->attr('value'));

        // sem ninguém na tabela, o cabeçalho (e a busca, para apagar o termo) continua lá
        $xhr = $client->xmlHttpRequest('GET', '/dashboard?busca=ninguem-com-esse-nome');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $xhr->filter('.db-table-card > .db-table-header > .db-busca.is-aberta'));
        self::assertCount(1, $xhr->filter('.db-table-card .db-empty'));
    }

    // ── Tendência ─────────────────────────────────────────────────────────

    #[TestDox('Pílulas no Total só em Total metas, Total demandas e Pastas criadas, com os números reais')]
    public function testPilulasDoTotal(): void
    {
        $client = static::createClient();
        $this->montarCenario($client);

        $crawler = $client->request('GET', '/dashboard?' . self::PERIODO);
        self::assertResponseIsSuccessful();

        // td do <tfoot>: [cargo, metas, ativas, vencidas, prazos, demandas, demandas ativas, criadas]
        $tds = $crawler->filter('.db-table-card table > tfoot > tr > td');
        self::assertCount(8, $tds);
        self::assertCount(3, $crawler->filter('.db-table-card table > tfoot > tr > td > .db-total-cel > .db-tend'));

        $metas = $tds->eq(1);
        self::assertSame('4', trim($metas->filter('td > .db-total-cel > .db-total-num')->text()));
        $p = $metas->filter('td > .db-total-cel > .db-tend');
        self::assertSame('+100%', trim($p->text()));
        self::assertSame('Subiu 100% em relação ao período anterior (2 → 4)', $p->attr('title'));
        self::assertStringContainsString('db-tend--bom', (string) $p->attr('class'));
        self::assertCount(1, $p->filter('.db-tend > i.bi-arrow-up-right'));

        foreach ([5 => 'demandas', 7 => 'pastas criadas'] as $i => $rotulo) {
            $p = $tds->eq($i)->filter('td > .db-total-cel > .db-tend');
            self::assertSame('−33%', trim($p->text()), $rotulo);
            self::assertSame('Caiu 33% em relação ao período anterior (3 → 2)', $p->attr('title'), $rotulo);
            self::assertStringContainsString('db-tend--ruim', (string) $p->attr('class'), $rotulo);
            self::assertCount(1, $p->filter('.db-tend > i.bi-arrow-down-right'), $rotulo);
        }

        foreach ([2, 3, 4, 6] as $i) {
            self::assertCount(0, $tds->eq($i)->filter('.db-tend'), 'Sem tendência nas métricas não reconstruíveis (coluna ' . $i . ')');
        }

        // o Total não é link: a soma conta duas vezes a meta com dois responsáveis
        self::assertCount(0, $crawler->filter('.db-table-card table > tfoot a'));
    }

    #[TestDox('Seta e balão em cada célula das três métricas; zero nos dois períodos reserva o espaço')]
    public function testSetasNasCelulas(): void
    {
        $client = static::createClient();
        $this->montarCenario($client);

        $crawler = $client->request('GET', '/dashboard?' . self::PERIODO);
        self::assertResponseIsSuccessful();

        // td da linha: [colab, cargo, metas, ativas, vencidas, prazos, demandas, demandas ativas, criadas]
        $ana = $this->linhaDe($crawler, 'Ana Lima')->filter('td');

        $seta = $ana->eq(2)->filter('td > .db-cel.db-cel--seta > .db-seta');
        self::assertCount(1, $seta, 'Seta logo à direita do número de Total metas');
        self::assertSame('Subiu 50% em relação ao período anterior (2 → 3)', $seta->attr('aria-label'));
        self::assertSame('0', $seta->attr('tabindex'), 'Focável por Tab (o balão abre no foco)');
        self::assertStringContainsString('db-seta--bom', (string) $seta->attr('class'));
        self::assertSame('+50%', trim($seta->filter('.db-seta > .db-dica > .db-dica-linha > .db-dica-pct')->text()));
        self::assertSame('antes: 2 → agora: 3', trim($seta->filter('.db-seta > .db-dica > .db-dica-de')->text()));
        self::assertSame('😄', trim($seta->filter('.db-dica-emoji')->text()));

        foreach ([6, 8] as $i) {
            $s = $ana->eq($i)->filter('td > .db-cel > .db-seta');
            self::assertSame('Caiu 33% em relação ao período anterior (3 → 2)', $s->attr('aria-label'));
            self::assertStringContainsString('db-seta--ruim', (string) $s->attr('class'));
            self::assertSame('−33%', trim($s->filter('.db-dica-pct')->text()));
            self::assertSame('😟', trim($s->filter('.db-dica-emoji')->text()));
        }
        foreach ([3, 4, 5, 7] as $i) {
            self::assertCount(0, $ana->eq($i)->filter('.db-seta'), 'Sem seta na coluna ' . $i);
        }

        $bruno = $this->linhaDe($crawler, 'Bruno Melo')->filter('td');
        $novo  = $bruno->eq(2)->filter('td > .db-cel > .db-seta');
        self::assertSame('Período anterior: 0', $novo->attr('aria-label'));
        self::assertSame('novo', trim($novo->filter('.db-dica-pct')->text()));
        self::assertSame('antes: 0 → agora: 1', trim($novo->filter('.db-dica-de')->text()));
        // pastas: zero nos dois períodos → espaço reservado, sem seta e fora do Tab
        $vazia = $bruno->eq(6)->filter('td > .db-cel.db-cel--seta > .db-seta.db-seta--vazia');
        self::assertCount(1, $vazia);
        self::assertNull($vazia->attr('tabindex'));
        self::assertCount(0, $vazia->filter('.db-dica'));
    }

    #[TestDox('Estável (< 2%) é cinza com →; sem movimento nos dois períodos, a pílula diz 0%')]
    public function testEstavelESemMovimento(): void
    {
        $client             = static::createClient();
        [$gestora, $tenant] = $this->criarGestorLogado($client);
        PastaFactory::createOne(['tenant' => $tenant, 'criadoPor' => $gestora, 'dataAbertura' => new \DateTimeImmutable('2024-02-10 10:00')]);
        PastaFactory::createOne(['tenant' => $tenant, 'criadoPor' => $gestora, 'dataAbertura' => new \DateTimeImmutable('2024-01-20 10:00')]);

        $crawler = $client->request('GET', '/dashboard?' . self::PERIODO);
        self::assertResponseIsSuccessful();
        $tds = $crawler->filter('.db-table-card table > tfoot > tr > td');

        $criadas = $tds->eq(7)->filter('td > .db-total-cel > .db-tend');
        self::assertSame('0%', trim($criadas->text()));
        self::assertSame('Estável, 0% em relação ao período anterior (1 → 1)', $criadas->attr('title'));
        self::assertStringContainsString('db-tend--igual', (string) $criadas->attr('class'));
        self::assertCount(1, $criadas->filter('.db-tend > i.bi-arrow-right'));

        $metas = $tds->eq(1)->filter('td > .db-total-cel > .db-tend');
        self::assertSame('0%', trim($metas->text()));
        self::assertSame('Sem movimento nos dois períodos', $metas->attr('title'));
        self::assertStringContainsString('db-tend--igual', (string) $metas->attr('class'));
    }

    #[TestDox('Sem período não há tendência nenhuma (nunca inventar um anterior)')]
    public function testSemPeriodoSemTendencia(): void
    {
        $client = static::createClient();
        $this->montarCenario($client);

        $crawler = $client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.db-table-card .db-tend'));
        self::assertCount(0, $crawler->filter('.db-table-card .db-seta'));
        self::assertCount(0, $crawler->filter('.db-table-card .db-cel--seta'));

        // só data_de também não é período completo
        $crawler = $client->request('GET', '/dashboard?data_de=2024-02-01');
        self::assertCount(0, $crawler->filter('.db-table-card .db-tend'));
    }

    // ── Números clicáveis ─────────────────────────────────────────────────

    #[TestDox('Números de metas por pessoa abrem /tarefas/equipe com responsável, status e período certos')]
    public function testLinksDeMetas(): void
    {
        $client = static::createClient();
        [, $ana] = $this->montarCenario($client);
        $id      = (string) $ana->getId();

        $crawler = $client->request('GET', '/dashboard?' . self::PERIODO);
        self::assertResponseIsSuccessful();
        $tds = $this->linhaDe($crawler, 'Ana Lima')->filter('td');

        $total = $tds->eq(2)->filter('td > .db-cel > a.db-num.db-num-link');
        self::assertSame('3', trim($total->text()));
        self::assertSame('Abrir: Metas de Ana Lima', $total->attr('title'));
        self::assertEquals(['/tarefas/equipe', ['status' => 'todas', 'responsavel' => $id, 'data_de' => '2024-02-01', 'data_ate' => '2024-02-29']], $this->destino($total));

        $ativas = $tds->eq(3)->filter('td > a.db-num.db-num-link');
        self::assertSame('3', trim($ativas->text()));
        self::assertEquals(['/tarefas/equipe', ['status' => 'ativas', 'responsavel' => $id, 'data_de' => '2024-02-01', 'data_ate' => '2024-02-29']], $this->destino($ativas));

        // relativas a hoje: sem período (e a meta vencida do outro escritório não conta)
        $venc = $tds->eq(4)->filter('td > .db-cel > a.db-pill.db-pill--venc.db-num-link');
        self::assertSame('1', trim($venc->text()));
        self::assertEquals(['/tarefas/equipe', ['status' => 'vencidas', 'responsavel' => $id]], $this->destino($venc));

        $praz = $tds->eq(5)->filter('td > .db-cel > a.db-pill.db-pill--praz.db-num-link');
        self::assertSame('1', trim($praz->text()));
        self::assertEquals(['/tarefas/equipe', ['status' => 'prazo_proximo', 'responsavel' => $id]], $this->destino($praz));
    }

    #[TestDox('Números de pastas por pessoa abrem o Acervo geral do Expediente com os filtros certos')]
    public function testLinksDePastas(): void
    {
        $client = static::createClient();
        [, $ana] = $this->montarCenario($client);
        $id      = (string) $ana->getId();

        $crawler = $client->request('GET', '/dashboard?' . self::PERIODO);
        self::assertResponseIsSuccessful();
        $tds = $this->linhaDe($crawler, 'Ana Lima')->filter('td');

        $dem = $tds->eq(6)->filter('td > .db-cel > a.db-num.db-num-link');
        self::assertSame('2', trim($dem->text()));
        self::assertEquals(['/expediente', ['painel' => 'acervo-geral', 'responsavel' => $id, 'data_de' => '2024-02-01', 'data_ate' => '2024-02-29']], $this->destino($dem));

        $ativas = $tds->eq(7)->filter('td > a.db-num.db-num-link');
        self::assertSame('2', trim($ativas->text()));
        self::assertEquals(['/expediente', ['painel' => 'acervo-geral', 'responsavel' => $id, 'status' => 'ativo', 'data_de' => '2024-02-01', 'data_ate' => '2024-02-29']], $this->destino($ativas));

        // por quem ABRIU a pasta: criado_por, sem responsável
        $criadas = $tds->eq(8)->filter('td > .db-cel > a.db-num.db-num-link');
        self::assertSame('2', trim($criadas->text()));
        self::assertSame('Abrir: Expediente · pastas criadas por Ana Lima', $criadas->attr('title'));
        self::assertEquals(['/expediente', ['painel' => 'acervo-geral', 'criado_por' => $id, 'data_de' => '2024-02-01', 'data_ate' => '2024-02-29']], $this->destino($criadas));
    }

    #[TestDox('Sem período, os links não levam data_de/data_ate')]
    public function testLinksSemPeriodo(): void
    {
        $client = static::createClient();
        [, $ana] = $this->montarCenario($client);
        $id      = (string) $ana->getId();

        $crawler = $client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();
        $tds = $this->linhaDe($crawler, 'Ana Lima')->filter('td');

        self::assertEquals(['/tarefas/equipe', ['status' => 'todas', 'responsavel' => $id]], $this->destino($tds->eq(2)->filter('td > .db-cel > a.db-num-link')));
        self::assertEquals(['/expediente', ['painel' => 'acervo-geral', 'criado_por' => $id]], $this->destino($tds->eq(8)->filter('td > .db-cel > a.db-num-link')));
    }

    #[TestDox('Zero não é link (a linha da gestora, toda zerada, não tem <a>)')]
    public function testZeroNaoELink(): void
    {
        $client = static::createClient();
        $this->montarCenario($client);

        $crawler = $client->request('GET', '/dashboard?' . self::PERIODO);
        self::assertResponseIsSuccessful();
        $gestora = $this->linhaDe($crawler, 'Gestora da Tela');
        self::assertCount(0, $gestora->filter('a'));
        self::assertCount(7, $gestora->filter('.db-num--zero'));
        // irmão com dado prova que o link existe quando o número é > 0
        self::assertGreaterThan(0, $this->linhaDe($crawler, 'Bruno Melo')->filter('td > .db-cel > a.db-num-link')->count());
    }

    #[TestDox('"ver pastas" do card Urgentes leva prioridade, período e o responsável filtrado')]
    public function testVerPastasDoCardUrgentes(): void
    {
        $client = static::createClient();
        [, $ana] = $this->montarCenario($client);

        $crawler = $client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();
        $sel = '.db-cards-row > * > .db-stat-card--urgentes > .db-card-corpo > .db-card-valor-linha > a.db-card-ver';
        $ver = $crawler->filter($sel);
        self::assertSame('ver pastas', trim($ver->text()));
        self::assertEquals(['/expediente', ['painel' => 'acervo-geral', 'prioridade' => 'urgente']], $this->destino($ver));
        self::assertCount(1, $crawler->filter('.db-cards-row a'), 'Só o card Urgentes tem link');

        $crawler = $client->request('GET', '/dashboard?' . self::PERIODO . '&responsavel=' . $ana->getId());
        self::assertEquals(
            ['/expediente', ['painel' => 'acervo-geral', 'prioridade' => 'urgente', 'responsavel' => (string) $ana->getId(), 'data_de' => '2024-02-01', 'data_ate' => '2024-02-29']],
            $this->destino($crawler->filter($sel)),
        );
    }
}
