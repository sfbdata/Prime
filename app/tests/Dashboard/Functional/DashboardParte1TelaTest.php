<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\DashboardController;
use App\Dashboard\Controller\RankingDoDashboardController;
use App\Dashboard\Preferencia\CatalogoDePreferenciasDoDashboard;
use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Dashboard\UseCase\ObterRankingDoColaboradorUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Tenant\Enum\SituacaoNaEquipe;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tarefa\TarefaFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * Dashboard, parte 1 (spec dashboard-parte-1) na TELA: período padrão Este mês, coluna Relatório,
 * troféu do campeão e o cartão do colaborador (ranking), com o isolamento entre escritórios.
 *
 * PHPUnit lê HTML, não posição nem som: a animação do troféu e a fanfarra são smoke do dono.
 */
#[CoversClass(DashboardController::class)]
#[CoversClass(RankingDoDashboardController::class)]
#[CoversClass(ObterRankingDoColaboradorUseCase::class)]
#[Group('dashboard')]
final class DashboardParte1TelaTest extends DashboardWebTestCase
{
    use Factories;

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function pasta(Tenant $tenant): Pasta
    {
        return PastaFactory::createOne(['tenant' => $tenant])->_real();
    }

    /** Meta criada hoje (ou em $criadaEm): concluída (conta no troféu) ou ainda ativa (só no total). */
    private function meta(Pasta $pasta, User $responsavel, bool $concluida = true, ?string $criadaEm = null): void
    {
        $tarefa = TarefaFactory::createOne(['pasta' => $pasta, 'status' => $concluida ? Tarefa::STATUS_CONCLUIDA : Tarefa::STATUS_PENDENTE])->_real();
        $tarefa->addResponsavel($responsavel);
        if ($concluida) {
            $tarefa->setDataConclusao(new \DateTimeImmutable());
        }
        if ($criadaEm !== null) {
            (new \ReflectionProperty(Tarefa::class, 'dataCriacao'))->setValue($tarefa, new \DateTimeImmutable($criadaEm));
        }
        $this->em()->flush();
    }

    private function linhaDe(Crawler $crawler, string $nome): Crawler
    {
        $linha = $crawler->filter('.db-table-card table.db-table > tbody > tr')->reduce(
            static fn (Crawler $tr): bool => str_contains($tr->filter('.db-colab-nome')->attr('title') ?? '', $nome),
        );
        self::assertCount(1, $linha, 'Linha de ' . $nome);

        return $linha;
    }

    #[TestDox('Sem data na URL: abre em Este mês (campos De/Até preenchidos e o botão Este mês ativo)')]
    public function testPeriodoPadraoEsteMes(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSame((new \DateTimeImmutable())->format('Y-m-01'), $crawler->filter('form[data-filtro-form] input[name="data_de"]')->attr('value'));
        self::assertSame((new \DateTimeImmutable())->format('Y-m-t'), $crawler->filter('form[data-filtro-form] input[name="data_ate"]')->attr('value'));
        self::assertSame('true', $crawler->filter('.db-seg > .db-seg-btn[data-periodo="mes"]')->attr('aria-pressed'));
    }

    #[TestDox('Datas enviadas vazias (o usuário apagou): o período fica aberto, como antes')]
    public function testDatasVaziasDeixamPeriodoAberto(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $crawler = $client->request('GET', '/dashboard?data_de=&data_ate=');

        self::assertSame('', $crawler->filter('form[data-filtro-form] input[name="data_de"]')->attr('value'));
        self::assertSame('false', $crawler->filter('.db-seg > .db-seg-btn[data-periodo="mes"]')->attr('aria-pressed'));
    }

    #[TestDox('Coluna Relatório: registros do autor no período, nas linhas e no Total; o registro do outro escritório não entra')]
    public function testColunaRelatorio(): void
    {
        $client = static::createClient();
        [$gestora, $tenant] = $this->criarGestorLogado($client);
        $ana = $this->criarColaborador($tenant, 'Ana Relatorio');
        static::getContainer()->get(PreferenciaDoUsuarioRepository::class)
            ->gravar($tenant, $gestora, CatalogoDePreferenciasDoDashboard::COLUNAS_EXTRAS, ['relatorio']);
        $pasta = $this->pasta($tenant);
        foreach ([$ana, $ana, $gestora] as $autor) {
            $this->mensagem($pasta, $autor);
        }
        $this->mensagem($this->pasta($this->criarTenant()), $ana);

        $crawler = $client->request('GET', '/dashboard');

        self::assertSame('Relatório', trim($crawler->filter('table.db-table > thead > tr > th[data-coluna="relatorio"]')->text()));
        self::assertSame('2', trim($this->linhaDe($crawler, 'Ana Relatorio')->filter('td[data-coluna="relatorio"] .db-num')->text()));
        self::assertStringContainsString('3', $crawler->filter('table.db-table > tfoot td[data-coluna="relatorio"]')->text());
    }

    #[TestDox('Troféu: só na linha de quem tem mais metas concluídas (total − ativas); meta ativa não conta')]
    public function testTrofeuNoCampeao(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $ana   = $this->criarColaborador($tenant, 'Ana Campea');
        $bruno = $this->criarColaborador($tenant, 'Bruno Ativas');
        $pasta = $this->pasta($tenant);
        $this->meta($pasta, $ana);
        $this->meta($pasta, $ana);
        $this->meta($pasta, $bruno);
        $this->meta($pasta, $bruno, false);
        $this->meta($pasta, $bruno, false);

        $crawler = $client->request('GET', '/dashboard');

        self::assertCount(1, $crawler->filter('table.db-table > tbody > tr .db-trofeu'), 'troféu em exatamente um colaborador');
        $linha = $this->linhaDe($crawler, 'Ana Campea');
        self::assertCount(1, $linha->filter('td.db-td-colab > .db-colab > i.bi-trophy-fill.db-trofeu'));
        self::assertNotNull($linha->attr('data-db-campeao'));
        self::assertSame('Mais metas concluídas no período', $linha->filter('.db-trofeu')->attr('title'));
        self::assertCount(1, $crawler->filter('.db-cel-card[data-db-campeao] .db-cel-nome > .db-trofeu'), 'no card do celular também');
        self::assertSame(
            'Botão direito: desempenho de Ana Campea',
            $crawler->filter('.db-cel-card[data-db-campeao] > .db-cel-cab[data-db-colab-url]')->attr('title'),
            'o card do celular leva o mesmo title (e o toque longo, no JS)',
        );
    }

    #[TestDox('Empate total (concluídas e vencidas): ninguém leva o troféu')]
    public function testEmpateSemTrofeu(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $pasta = $this->pasta($tenant);
        $this->meta($pasta, $this->criarColaborador($tenant, 'Ana Empate'));
        $this->meta($pasta, $this->criarColaborador($tenant, 'Bruno Empate'));

        $crawler = $client->request('GET', '/dashboard');

        self::assertCount(0, $crawler->filter('.db-trofeu'));
    }

    /** @return array<string, mixed> */
    private function cartao(string $url): array
    {
        static::getClient()->request('GET', $url);
        self::assertResponseIsSuccessful();

        return json_decode((string) static::getClient()->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    #[TestDox('Cartão do colaborador: abre no período do painel; a janela do seletor é a alternativa; Rendimento contra a média dos outros')]
    public function testRankingDoColaborador(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $ana   = $this->criarColaborador($tenant, 'Ana Ranking');
        $bruno = $this->criarColaborador($tenant, 'Bruno Ranking');
        $pasta = $this->pasta($tenant);
        $this->meta($pasta, $ana);
        $this->meta($pasta, $ana, false);
        $this->meta($pasta, $bruno);
        $this->meta($pasta, $bruno);

        $crawler = $client->request('GET', '/dashboard');
        $url     = $this->linhaDe($crawler, 'Ana Ranking')->filter('.db-colab')->attr('data-db-colab-url');
        self::assertSame('/dashboard/colaborador/' . $ana->getId() . '/ranking', $url);

        $hoje = (new \DateTimeImmutable())->format('Y-m-d');
        $json = $this->cartao($url . '?data_de=' . $hoje . '&data_ate=' . $hoje);
        self::assertSame(['periodo', $hoje, $hoje, 2, 3, false], [$json['janela'], $json['dataDe'], $json['dataAte'], $json['posicao'], $json['pessoas'], $json['campeao']]);
        self::assertSame(['eu' => 1, 'media' => 1, 'outros' => 2], $json['rendimento'], 'gestora 0 e Bruno 2: média 1 dos outros');

        $json = $this->cartao($url . '?janela=trimestre&data_de=2020-01-01&data_ate=2020-01-02');
        self::assertSame(['trimestre', 2], [$json['janela'], $json['posicao']], 'a janela vence o período do painel');

        $vazio = $this->cartao($url . '?data_de=2020-01-01&data_ate=2020-01-02');
        self::assertSame(['eu' => 0, 'media' => 0, 'outros' => 2], $vazio['rendimento'], 'período do painel sem meta');
    }

    #[TestDox('1º do mês: o mês fechado em que foi o campeão, pela regra do troféu; o mês corrente não entra')]
    public function testPrimeiroDoMes(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $ana   = $this->criarColaborador($tenant, 'Ana Historico');
        $bruno = $this->criarColaborador($tenant, 'Bruno Historico');
        $pasta = $this->pasta($tenant);
        $mesPassado = (new \DateTimeImmutable('first day of last month'))->setTime(10, 0);
        $this->meta($pasta, $ana, true, $mesPassado->format('Y-m-d H:i'));
        $this->meta($pasta, $ana, true, $mesPassado->format('Y-m-d H:i'));
        $this->meta($pasta, $bruno, true, $mesPassado->format('Y-m-d H:i'));
        $this->meta($pasta, $bruno);
        $this->meta($pasta, $bruno);
        $this->meta($this->pasta($this->criarTenant()), $bruno, true, $mesPassado->format('Y-m-d H:i'));

        $ana1 = $this->cartao('/dashboard/colaborador/' . $ana->getId() . '/ranking')['primeiros'];
        $ano  = (int) $mesPassado->format('Y');
        self::assertSame([(int) $mesPassado->format('n')], $ana1['ganhos'][$ano] ?? null);
        self::assertSame((int) (new \DateTimeImmutable())->format('n') - 1, $ana1['ultimoMesFechado']);
        self::assertContains((int) (new \DateTimeImmutable())->format('Y'), $ana1['anos']);

        $bruno1 = $this->cartao('/dashboard/colaborador/' . $bruno->getId() . '/ranking')['primeiros'];
        self::assertSame([], $bruno1['ganhos'], 'Bruno lidera o mês corrente, que não fechou; a meta do outro escritório não conta');
    }

    #[TestDox('Cartão: vínculo inativo no mesmo escritório dá 404, como o de outro escritório')]
    public function testRankingVinculoInativo(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $saiu = $this->criarColaborador($tenant, 'Saiu Escritorio');
        $vinculo = $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $saiu, 'tenant' => $tenant]);
        self::assertNotNull($vinculo);
        $vinculo->alterarSituacao(SituacaoNaEquipe::Suspenso); // isActive=false
        $this->em()->flush();

        $client->request('GET', '/dashboard/colaborador/' . $saiu->getId() . '/ranking');
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Relatório tem tendência: seta na linha e pílula no Total contra o período anterior equivalente')]
    public function testTendenciaDoRelatorio(): void
    {
        $client = static::createClient();
        [$gestora, $tenant] = $this->criarGestorLogado($client);
        static::getContainer()->get(PreferenciaDoUsuarioRepository::class)
            ->gravar($tenant, $gestora, CatalogoDePreferenciasDoDashboard::COLUNAS_EXTRAS, ['relatorio']);
        $pasta = $this->pasta($tenant);
        $this->mensagem($pasta, $gestora, '2024-03-10 10:00');
        $this->mensagem($pasta, $gestora, '2024-03-12 10:00');
        $this->mensagem($pasta, $gestora, '2024-02-20 10:00');

        $crawler = $client->request('GET', '/dashboard?data_de=2024-03-01&data_ate=2024-03-31');

        self::assertGreaterThanOrEqual(1, $crawler->filter('table.db-table > tbody td[data-coluna="relatorio"] .db-seta')->count());
        self::assertSame('+100%', trim($crawler->filter('table.db-table > tfoot td[data-coluna="relatorio"] .db-tend')->text()));
    }

    #[TestDox('Cartão: colaborador de outro escritório dá 404 (IDOR) e sem o módulo bi dá 403')]
    public function testRankingIsolamento(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);
        $estranho = $this->criarColaborador($this->criarTenant(), 'Outro Escritorio');

        $client->request('GET', '/dashboard/colaborador/' . $estranho->getId() . '/ranking');
        self::assertResponseStatusCodeSame(404);

        $tenant = $this->criarTenant();
        $semBi  = $this->criarColaborador($tenant, 'Sem Painel');
        $this->logarComTenant($client, $semBi, $tenant);
        $client->request('GET', '/dashboard/colaborador/' . $semBi->getId() . '/ranking');
        self::assertResponseStatusCodeSame(403);
    }

    private function mensagem(Pasta $pasta, User $autor, ?string $criadaEm = null): void
    {
        $m = new PastaMensagem();
        $m->setPasta($pasta);
        $m->setAutor($autor);
        $m->setTenant($pasta->getTenant());
        $m->setConteudo('Contato com o cliente');
        if ($criadaEm !== null) {
            (new \ReflectionProperty(PastaMensagem::class, 'criadaEm'))->setValue($m, new \DateTimeImmutable($criadaEm));
        }
        $this->em()->persist($m);
        $this->em()->flush();
    }
}
