<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\MetaDePastasDoDashboardController;
use App\Dashboard\Entity\DashboardMetaPastas;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Meta de pastas do mês (DASH-016): POST /dashboard/meta-pastas e /remover, e a tela do card "Pastas criadas".
 * Só o primário grava (403 aos demais); o escritório é o da sessão; CSRF no header. Na tela, o botão de editar é
 * só do primário; os demais veem a meta e o status sem o lápis. O desenho: "01 - Dashboard - VS 25.10.26".
 */
#[CoversClass(MetaDePastasDoDashboardController::class)]
final class MetaDePastasDoDashboardTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const PERMISSOES_GESTOR = ['modules.expediente.view', 'resources.pasta.view', 'modules.bi.view'];

    private const CORPO_DO_CARD = '.db-stat-card--pastas > .db-card-corpo';

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCliente();
    }

    /** @return array{primario: User, tenant: Tenant, gestor: User} */
    private function cenario(): array
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $gestor = $this->criarColaborador($tenant, 'Gestor Secundário', self::PERMISSOES_GESTOR);

        return ['primario' => $primario, 'tenant' => $tenant, 'gestor' => $gestor];
    }

    /** @param array<string, mixed>|null $corpo */
    private function postar(string $rota, ?array $corpo, string $token = 'TOKEN_ajax'): void
    {
        $this->client->request(
            'POST',
            '/dashboard/meta-pastas' . $rota,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            json_encode($corpo ?? new \stdClass(), JSON_THROW_ON_ERROR),
        );
    }

    /** @return list<array{valor: int, mes: string}> */
    private function linhasDoBanco(Tenant $tenant): array
    {
        return array_map(
            static fn (array $l): array => ['valor' => (int) $l['valor'], 'mes' => (string) $l['mes']],
            $this->em()->getConnection()->fetchAllAssociative('SELECT valor, mes FROM dashboard_meta_pastas WHERE tenant_id = :t ORDER BY id', ['t' => $tenant->getId()]),
        );
    }

    private function gravarMeta(Tenant $tenant, User $autor, int $valor, string $mes = 'first day of this month'): void
    {
        $this->em()->persist(new DashboardMetaPastas($this->em()->getReference(Tenant::class, $tenant->getId()), new \DateTimeImmutable($mes), $valor, $this->em()->getReference(User::class, $autor->getId())));
        $this->em()->flush();
    }

    private function tela(string $url = '/dashboard'): Crawler
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return new Crawler((string) $this->client->getResponse()->getContent());
    }

    #[TestDox('Primário define a meta: grava no dia 1º do mês corrente, responde o valor e a tela mostra "0 de 40 no mês"')]
    public function testPrimarioDefine(): void
    {
        $c = $this->cenario();
        $this->entrar($c['primario'], $c['tenant']);

        $this->postar('', ['valor' => 40]);

        self::assertResponseIsSuccessful();
        self::assertSame(['valor' => 40, 'desde' => (new \DateTimeImmutable())->format('Y-m')], json_decode((string) $this->client->getResponse()->getContent(), true));
        self::assertSame([['valor' => 40, 'mes' => (new \DateTimeImmutable())->format('Y-m-01')]], $this->linhasDoBanco($c['tenant']));
        $linha = $this->tela()->filter(self::CORPO_DO_CARD . ' > .db-meta > .db-meta-linha');
        self::assertCount(1, $linha);
        self::assertSame('de', $linha->filter('.db-meta-feitas > span')->first()->text());
        self::assertSame('40', $linha->filter('.db-meta-feitas > b')->last()->text());
    }

    #[TestDox('Trocar a meta no mesmo mês grava uma linha nova (o histórico fica) e vale a última')]
    public function testTrocarGravaLinhaNova(): void
    {
        $c = $this->cenario();
        $this->entrar($c['primario'], $c['tenant']);

        $this->postar('', ['valor' => 40]);
        $this->postar('', ['valor' => 55]);

        self::assertCount(2, $this->linhasDoBanco($c['tenant']));
        $meta = $this->tela()->filter(self::CORPO_DO_CARD . ' > .db-meta > .db-meta-linha > .db-meta-feitas > b')->last();
        self::assertSame('55', $meta->text());
    }

    #[TestDox('Quem não é primário (mesmo com o Dashboard) recebe 403 ao definir e ao remover, e nada é gravado')]
    public function testNaoPrimario403(): void
    {
        $c = $this->cenario();
        $this->entrar($c['gestor'], $c['tenant']);

        $this->postar('', ['valor' => 40]);
        self::assertResponseStatusCodeSame(403);
        $this->postar('/remover', null);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->linhasDoBanco($c['tenant']));
    }

    #[TestDox('Escritório da sessão: a meta de um escritório não aparece nem altera o de outro')]
    public function testOutroEscritorioNaoVaza(): void
    {
        $c = $this->cenario();
        [$primarioB, $tenantB] = $this->criarEscritorio();
        $this->gravarMeta($c['tenant'], $c['primario'], 77);

        $this->entrar($primarioB, $tenantB);
        $this->postar('', ['valor' => 12]);
        self::assertResponseIsSuccessful();

        self::assertSame([['valor' => 77, 'mes' => (new \DateTimeImmutable())->format('Y-m-01')]], $this->linhasDoBanco($c['tenant']), 'o escritório A segue intacto');
        self::assertSame([12], array_column($this->linhasDoBanco($tenantB), 'valor'));
        $tela = $this->tela();
        self::assertSame('12', $tela->filter(self::CORPO_DO_CARD . ' > .db-meta > .db-meta-linha > .db-meta-feitas > b')->last()->text(), 'o B vê a dele, não a de 77');
    }

    #[TestDox('CSRF inválido dá 403 e nada é gravado')]
    public function testCsrf(): void
    {
        $c = $this->cenario();
        $this->entrar($c['primario'], $c['tenant']);

        $this->postar('', ['valor' => 40], 'TOKEN_errado');
        self::assertResponseStatusCodeSame(403);
        $this->postar('/remover', null, 'TOKEN_errado');
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->linhasDoBanco($c['tenant']));
    }

    #[TestDox('Corpo ou valor inválido (0, acima de 9999, texto, decimal, ausente) dá 400 e nada é gravado')]
    public function testValorInvalido400(): void
    {
        $c = $this->cenario();
        $this->entrar($c['primario'], $c['tenant']);

        foreach ([['valor' => 0], ['valor' => 10000], ['valor' => 'abc'], ['valor' => 4.5], ['outro' => 1]] as $corpo) {
            $this->postar('', $corpo);
            self::assertResponseStatusCodeSame(400, json_encode($corpo, JSON_THROW_ON_ERROR));
        }
        self::assertSame([], $this->linhasDoBanco($c['tenant']));
    }

    #[TestDox('Remover grava valor 0 no mês corrente e o card volta ao "Definir meta do mês"; GET não é aceito')]
    public function testRemover(): void
    {
        $c = $this->cenario();
        $this->gravarMeta($c['tenant'], $c['primario'], 30, 'first day of 2 months ago');
        $this->entrar($c['primario'], $c['tenant']);
        self::assertCount(1, $this->tela()->filter(self::CORPO_DO_CARD . ' > .db-meta'), 'meta herdada aparece');

        $this->postar('/remover', null);

        self::assertResponseIsSuccessful();
        self::assertSame(['valor' => 0, 'desde' => (new \DateTimeImmutable())->format('Y-m')], json_decode((string) $this->client->getResponse()->getContent(), true));
        self::assertSame([30, 0], array_column($this->linhasDoBanco($c['tenant']), 'valor'));
        $tela = $this->tela();
        self::assertCount(0, $tela->filter(self::CORPO_DO_CARD . ' > .db-meta'));
        self::assertCount(1, $tela->filter(self::CORPO_DO_CARD . ' > button.db-meta-definir[data-db-meta-editar]'));

        $this->client->request('GET', '/dashboard/meta-pastas');
        self::assertResponseStatusCodeSame(405);
    }

    #[TestDox('Tela, primário sem meta: o botão "Definir meta do mês" é filho direto do corpo do card; com meta, o lápis é um botão')]
    public function testTelaDoPrimario(): void
    {
        $c = $this->cenario();
        $this->entrar($c['primario'], $c['tenant']);

        $sem = $this->tela();
        self::assertCount(1, $sem->filter(self::CORPO_DO_CARD . ' > button.db-meta-definir[data-db-meta-editar]'));
        self::assertSame('Definir meta do mês', trim($sem->filter(self::CORPO_DO_CARD . ' > button.db-meta-definir')->text()));
        self::assertCount(1, $sem->filter('.db-page[data-db-meta-pode="1"]'));

        $this->gravarMeta($c['tenant'], $c['primario'], 40);
        $com = $this->tela();
        $botao = $com->filter(self::CORPO_DO_CARD . ' > .db-meta > .db-meta-linha > button.db-meta-feitas--botao[data-db-meta-editar]');
        self::assertCount(1, $botao);
        self::assertSame('Alterar meta do mês', $botao->attr('title'));
        self::assertCount(1, $botao->filter('i.bi-pencil'));
        // O card traz o estado para o editor (valor, mês de vigência, mês de hoje e dias úteis do mês).
        $card = $com->filter('.db-stat-card--pastas');
        self::assertSame('40', $card->attr('data-db-meta-valor'));
        self::assertSame((new \DateTimeImmutable())->format('Y-m'), $card->attr('data-db-meta-mes'));
        self::assertGreaterThan(0, (int) $card->attr('data-db-meta-uteis'));
        // Estados do desenho: trilho com 3 marcas em 25/50/75 % e o traço do ritmo, todos filhos diretos do trilho.
        $trilho = $com->filter(self::CORPO_DO_CARD . ' > .db-meta > .db-meta-trilho');
        self::assertCount(1, $trilho->filter('.db-meta-trilho > .db-meta-fill'));
        self::assertCount(3, $trilho->filter('.db-meta-trilho > .db-meta-quarto'));
        self::assertCount(1, $trilho->filter('.db-meta-trilho > .db-meta-marco'));
        self::assertCount(1, $com->filter(self::CORPO_DO_CARD . ' > .db-meta > .db-meta-linha > .db-meta-status'));
        // A dica (title) diz o previsto, o que falta e o ritmo em dias úteis, sem placeholder cru.
        $dica = (string) $com->filter(self::CORPO_DO_CARD . ' > .db-meta')->attr('title');
        self::assertMatchesRegularExpression('/^Previsto até hoje: \d+ \(dia útil \d+ de \d+\)\. Faltam 40 em \d+ dias úteis: [\d,]+ por dia útil, \d+ por semana\. O traço marca/u', $dica);
        self::assertStringNotContainsString('{', $dica);
    }

    #[TestDox('Tela, quem não é primário: vê a meta e o status, sem botão de editar e sem o "Definir"; sem meta, nada')]
    public function testTelaDoNaoPrimario(): void
    {
        $c = $this->cenario();
        $this->entrar($c['gestor'], $c['tenant']);

        $sem = $this->tela();
        self::assertCount(0, $sem->filter('[data-db-meta-editar]'));
        self::assertCount(0, $sem->filter(self::CORPO_DO_CARD . ' > .db-meta'));
        self::assertCount(1, $sem->filter('.db-page[data-db-meta-pode="0"]'));

        $this->gravarMeta($c['tenant'], $c['primario'], 40);
        $com = $this->tela();
        self::assertCount(1, $com->filter(self::CORPO_DO_CARD . ' > .db-meta > .db-meta-linha > span.db-meta-feitas'));
        self::assertCount(0, $com->filter('[data-db-meta-editar]'), 'sem lápis');
        self::assertCount(0, $com->filter('.db-meta-feitas i.bi-pencil'));
        self::assertCount(1, $com->filter(self::CORPO_DO_CARD . ' > .db-meta > .db-meta-linha > .db-meta-status'), 'o status segue visível');
        self::assertNull($com->filter('.db-stat-card--pastas')->attr('data-db-meta-valor'), 'o estado do editor nem sai no HTML');
    }

    #[TestDox('Meta batida: o realizado é o número do card Pastas criadas; "Meta batida" no lugar do ritmo e dica sem ritmo')]
    public function testMetaBatida(): void
    {
        $c = $this->cenario();
        $this->gravarMeta($c['tenant'], $c['primario'], 1);
        $ana = $this->criarColaborador($c['tenant'], 'Ana Equipe');
        $pasta = $this->criarPasta($c['tenant']);
        $pasta->setCriadoPor($this->em()->getReference(User::class, $ana->getId()));
        $this->em()->flush();
        $this->entrar($c['gestor'], $c['tenant']);

        $tela = $this->tela();

        self::assertSame('1', trim($tela->filter('.db-stat-card--pastas .db-stat-num')->text()));
        self::assertSame('1', $tela->filter(self::CORPO_DO_CARD . ' > .db-meta > .db-meta-linha > .db-meta-feitas > b')->first()->text());
        $status = $tela->filter(self::CORPO_DO_CARD . ' > .db-meta > .db-meta-linha > .db-meta-status');
        self::assertSame('Meta batida', trim($status->text()));
        self::assertCount(1, $status->filter('i.bi-check-circle-fill'));
        self::assertSame('Meta do mês alcançada', $tela->filter(self::CORPO_DO_CARD . ' > .db-meta')->attr('title'));
    }

    #[TestDox('Com responsável filtrado ou fora do mês inteiro a meta não aparece no card; no mês inteiro, sim')]
    public function testRecortesEscondem(): void
    {
        $c = $this->cenario();
        $this->gravarMeta($c['tenant'], $c['primario'], 40);
        $this->entrar($c['primario'], $c['tenant']);

        $mes = $this->tela();
        self::assertCount(1, $mes->filter(self::CORPO_DO_CARD . ' > .db-meta'));

        $hoje = new \DateTimeImmutable();
        $ate = $hoje->format('Y-m-01') === $hoje->format('Y-m-d') ? $hoje->modify('+1 day') : $hoje;
        $parcial = $this->tela('/dashboard?data_de=' . $hoje->format('Y-m-01') . '&data_ate=' . $ate->format('Y-m-d'));
        self::assertCount($hoje->format('Y-m-d') === $hoje->format('Y-m-t') ? 1 : 0, $parcial->filter(self::CORPO_DO_CARD . ' > .db-meta'));

        $porPessoa = $this->tela('/dashboard?responsavel=' . $c['gestor']->getId());
        self::assertCount(0, $porPessoa->filter(self::CORPO_DO_CARD . ' > .db-meta'));
        self::assertCount(0, $porPessoa->filter(self::CORPO_DO_CARD . ' > .db-meta-definir'));
    }

    #[TestDox('A meta do mês anterior vale no mês corrente (herdada); o editor recebe o mês em que ela começou')]
    public function testHerdada(): void
    {
        $c = $this->cenario();
        $this->gravarMeta($c['tenant'], $c['primario'], 25, 'first day of 2 months ago');
        $this->entrar($c['primario'], $c['tenant']);

        $card = $this->tela()->filter('.db-stat-card--pastas');

        self::assertSame('25', $card->attr('data-db-meta-valor'));
        self::assertSame((new \DateTimeImmutable('first day of 2 months ago'))->format('Y-m'), $card->attr('data-db-meta-desde'));
    }

    #[TestDox('O dashboard liga o CSS e o JS da meta e entrega as duas rotas ao JS')]
    public function testPaginaLigaOsRecursos(): void
    {
        $c = $this->cenario();
        $this->entrar($c['primario'], $c['tenant']);

        $html = (string) $this->tela()->html();
        $pagina = $this->tela()->filter('.db-page');

        self::assertStringContainsString('css/dashboard-meta.css', $html);
        self::assertStringContainsString('js/dashboard-meta.js', $html);
        self::assertSame('/dashboard/meta-pastas', $pagina->attr('data-db-meta-url'));
        self::assertSame('/dashboard/meta-pastas/remover', $pagina->attr('data-db-meta-remover-url'));
    }
}
