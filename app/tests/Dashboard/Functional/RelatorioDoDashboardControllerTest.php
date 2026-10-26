<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\RelatorioDoDashboardController;
use App\Dashboard\Entity\DashboardZeramento;
use App\Dashboard\Preferencia\CatalogoDePreferenciasDoDashboard;
use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Dashboard\UseCase\ObterDadosDashboardUseCase;
use App\Dashboard\UseCase\ObterRelatorioDeRegistrosDoColaboradorUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Pasta\Service\AcessoAPasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * GET /dashboard/relatorio/{userId}: o modal "Ver relatório de X" (spec dashboard-parte-1, PR-D). A lista usa o
 * critério da contagem da coluna Relatório (RegistroDePastaNoPeriodo), então o total do modal = o número da
 * célula do Painel (regra B12), com cadeado, lápide, período e o corte do Zerar.
 */
#[CoversClass(RelatorioDoDashboardController::class)]
#[CoversClass(ObterRelatorioDeRegistrosDoColaboradorUseCase::class)]
final class RelatorioDoDashboardControllerTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const PERMISSOES_GESTOR = ['modules.expediente.view', 'resources.pasta.view', 'modules.bi.view'];
    private const DE  = '2026-09-01';
    private const ATE = '2026-09-30';

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCliente();
    }

    private function registro(string $classe, Pasta $pasta, User $autor, string $quando, string $conteudo = 'Registro de teste'): object
    {
        $r = new $classe();
        $r->setPasta($pasta);
        $r->setAutor($autor);
        $r->setTenant($pasta->getTenant());
        $r->setConteudo($conteudo);
        (new \ReflectionProperty($classe, 'criadaEm'))->setValue($r, new \DateTimeImmutable($quando));
        $this->em()->persist($r);
        $this->em()->flush();

        return $r;
    }

    /**
     * Ana na pasta A: 2 mensagens (uma é resposta), 1 financeiro, 1 detalhes, 1 cartão do sistema (fora), 1 fora do
     * período. Na pasta B (oculta pelo cadeado para a gestora): 2 mensagens. Na lápide: 1. Bruno: 1. Outro escritório: 1.
     *
     * @return array<string, mixed>
     */
    private function cenario(): array
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $gestora = $this->criarColaborador($tenant, 'Gestora Relatorio', self::PERMISSOES_GESTOR);
        $ana     = $this->criarColaborador($tenant, 'Ana Equipe');
        $bruno   = $this->criarColaborador($tenant, 'Bruno Equipe');
        $semBi   = $this->criarColaborador($tenant, 'Sem Painel');

        $a = $this->criarPasta($tenant, 'CLIENTE A');
        $b = $this->criarPasta($tenant, 'CLIENTE B');
        $lapide = $this->criarPasta($tenant, 'CLIENTE LAPIDE');

        $m1 = $this->registro(PastaMensagem::class, $a, $ana, '2026-09-05 09:15', '<p>Primeira <b>anotação</b></p><script>alert(1)</script> &amp; fim');
        $resposta = $this->registro(PastaMensagem::class, $a, $ana, '2026-09-06 14:30');
        $resposta->setRespostaA($m1);
        $this->registro(PastaObservacaoFinanceira::class, $a, $ana, '2026-09-10 16:00');
        $this->registro(PastaObservacaoDetalhes::class, $a, $ana, '2026-09-20 08:05');
        $sistema = $this->registro(PastaMensagem::class, $a, $ana, '2026-09-11 10:00');
        $sistema->setSistema('andamento');
        $this->registro(PastaMensagem::class, $a, $ana, '2026-08-31 23:00'); // fora do período
        $this->registro(PastaMensagem::class, $b, $ana, '2026-09-07 10:00');
        $this->registro(PastaMensagem::class, $b, $ana, '2026-09-08 10:00');
        $this->registro(PastaMensagem::class, $lapide, $ana, '2026-09-09 10:00');
        $this->registro(PastaMensagem::class, $a, $bruno, '2026-09-12 10:00');
        [, $outro] = $this->criarEscritorio();
        $intruso = $this->criarColaborador($outro, 'Ana de Outro');
        $this->registro(PastaMensagem::class, $this->criarPasta($outro), $intruso, '2026-09-12 10:00');
        $lapide->marcarExcluida($primario, new \DateTimeImmutable());
        $this->em()->flush();
        $this->trancar($b, $primario, [[$ana, NivelDeAcesso::Editar]]);

        return compact('primario', 'tenant', 'gestora', 'ana', 'bruno', 'semBi', 'intruso', 'outro', 'a', 'b');
    }

    /** @return array<string, mixed> */
    private function modal(User $quem, Tenant $tenant, int $pessoa, array $query = ['data_de' => self::DE, 'data_ate' => self::ATE]): array
    {
        $this->entrar($quem, $tenant);
        $this->client->request('GET', '/dashboard/relatorio/' . $pessoa . '?' . http_build_query($query));
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function celulaDoPainel(User $quem, Tenant $tenant, User $pessoa): int
    {
        $ocultas = static::getContainer()->get(AcessoAPasta::class)->idsOcultosPara($quem, (int) $tenant->getId());
        $saida   = static::getContainer()->get(ObterDadosDashboardUseCase::class)->executar(
            $tenant,
            new \DateTimeImmutable('2026-10-26'),
            ['data_de' => self::DE, 'data_ate' => self::ATE],
            ['relatorio'],
            $ocultas,
        );
        foreach ($saida->porAdvogado as $linha) {
            if ($linha->userId === $pessoa->getId()) {
                return (int) ($linha->extras['relatorio'] ?? 0);
            }
        }
        self::fail('pessoa sem linha no painel');
    }

    /** @param array<string, mixed> $dados */
    private function itens(array $dados): int
    {
        return array_sum(array_map(static fn (array $g): int => count($g['itens']), $dados['grupos']));
    }

    #[TestDox('PARIDADE: o total do modal = o número da célula do Painel, para quem vê tudo e para quem tem pasta oculta')]
    public function testParidadeComOCadeado(): void
    {
        $c = $this->cenario();

        // Gestora fora da grade: a pasta B (2 mensagens) não conta no painel nem aparece no modal.
        $dados = $this->modal($c['gestora'], $c['tenant'], $c['ana']->getId());
        self::assertSame(4, $dados['total'], 'mensagem + resposta + financeiro + detalhes');
        self::assertSame($this->celulaDoPainel($c['gestora'], $c['tenant'], $c['ana']), $dados['total']);
        self::assertSame($dados['total'], $this->itens($dados));
        self::assertSame(0, $dados['restantes']);
        self::assertCount(1, $dados['grupos']);
        self::assertSame($c['a']->getId(), $dados['grupos'][0]['pastaId']);

        // O primário vê a oculta: painel e modal sobem juntos.
        $primario = $this->modal($c['primario'], $c['tenant'], $c['ana']->getId());
        self::assertSame(6, $primario['total']);
        self::assertSame($this->celulaDoPainel($c['primario'], $c['tenant'], $c['ana']), $primario['total']);
        self::assertCount(2, $primario['grupos']);
    }

    #[TestDox('agrupa por pasta, mais novo primeiro, com data, hora, aba e link da pasta; trecho sem HTML')]
    public function testFormaDoRetorno(): void
    {
        $c = $this->cenario();
        $dados = $this->modal($c['gestora'], $c['tenant'], $c['ana']->getId());

        self::assertSame('Ana Equipe', $dados['nome']);
        self::assertNull($dados['corte']);
        $grupo = $dados['grupos'][0];
        self::assertSame('/pasta/' . $c['a']->getId() . '#dados', $grupo['url']);
        self::assertSame('CLIENTE A', $grupo['cliente']);
        self::assertSame($c['a']->getNup(), $grupo['titulo']);

        self::assertSame(['detalhes', 'financeiro', 'dados', 'dados'], array_column($grupo['itens'], 'aba'));
        self::assertSame(['20/09/2026', '10/09/2026', '06/09/2026', '05/09/2026'], array_column($grupo['itens'], 'data'));
        self::assertSame('08:05', $grupo['itens'][0]['hora']);
        self::assertSame('09:15', $grupo['itens'][3]['hora']);

        $trecho = $grupo['itens'][3]['trecho'];
        self::assertSame('Primeira anotação alert(1) & fim', $trecho, 'tags e entidades saem; o texto da tag script é só texto');
        self::assertStringNotContainsString('<', $trecho);
    }

    #[TestDox('sem módulo bi: 403; sem login: redireciona; pessoa de outro escritório e vínculo inativo: 404')]
    public function testGuardasEIdor(): void
    {
        $c = $this->cenario();

        $this->entrar($c['semBi'], $c['tenant']);
        $this->client->request('GET', '/dashboard/relatorio/' . $c['ana']->getId());
        self::assertResponseStatusCodeSame(403);

        $this->entrar($c['gestora'], $c['tenant']);
        $this->client->request('GET', '/dashboard/relatorio/' . $c['intruso']->getId());
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('Ana de Outro', (string) $this->client->getResponse()->getContent());

        $ut = $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $c['bruno'], 'tenant' => $c['tenant']]);
        (new \ReflectionProperty(UserTenant::class, 'isActive'))->setValue($ut, false);
        $this->em()->flush();
        $this->client->request('GET', '/dashboard/relatorio/' . $c['bruno']->getId());
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/dashboard/relatorio/999999999');
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('lápide, cartão do sistema, fora do período e outro escritório não entram')]
    public function testLapideSistemaPeriodoEIsolamento(): void
    {
        $c = $this->cenario();

        $semPeriodo = $this->modal($c['primario'], $c['tenant'], $c['ana']->getId(), []);
        self::assertSame(7, $semPeriodo['total'], 'sem período entra a de 31/08; lápide e sistema continuam fora');
        self::assertSame(7, $this->itens($semPeriodo));

        $bruno = $this->modal($c['gestora'], $c['tenant'], $c['bruno']->getId());
        self::assertSame(1, $bruno['total']);
        self::assertSame('Bruno Equipe', $bruno['nome']);
    }

    #[TestDox('Zerar na coluna Relatório: o modal aplica o mesmo corte da célula (paridade)')]
    public function testZerarComCorte(): void
    {
        $c = $this->cenario();
        $this->em()->persist(new DashboardZeramento($c['tenant'], 'relatorio', $c['ana'], new \DateTimeImmutable('2026-09-10 12:00'), $c['primario']));
        $this->em()->flush();

        $dados = $this->modal($c['gestora'], $c['tenant'], $c['ana']->getId());
        self::assertSame(2, $dados['total'], 'só financeiro (10/09 16:00) e detalhes (20/09)');
        self::assertSame($this->celulaDoPainel($c['gestora'], $c['tenant'], $c['ana']), $dados['total']);
        self::assertNotNull($dados['corte']);

        // O corte é da Ana: o do Bruno segue completo.
        self::assertSame(1, $this->modal($c['gestora'], $c['tenant'], $c['bruno']->getId())['total']);
    }

    #[TestDox('o limite de linhas corta a lista mas o total continua exato, com o resto em "restantes"')]
    public function testLimiteDeLinhas(): void
    {
        $c = $this->cenario();
        $limite = ObterRelatorioDeRegistrosDoColaboradorUseCase::LIMITE_DE_LINHAS;
        $conexao = $this->em()->getConnection();
        $conexao->executeStatement(
            'INSERT INTO pasta_mensagem (pasta_id, autor_id, tenant_id, conteudo, criada_em, eh_resposta) '
            . 'SELECT :pasta, :autor, :tenant, \'em lote\', TIMESTAMP \'2026-09-15 10:00:00\' + (g || \' minutes\')::interval, false FROM generate_series(1, :n) g',
            ['pasta' => $c['a']->getId(), 'autor' => $c['ana']->getId(), 'tenant' => $c['tenant']->getId(), 'n' => $limite + 5],
        );

        $dados = $this->modal($c['gestora'], $c['tenant'], $c['ana']->getId());
        self::assertSame(4 + $limite + 5, $dados['total']);
        self::assertSame($limite, $this->itens($dados));
        self::assertSame(4 + 5, $dados['restantes']);
        self::assertSame($this->celulaDoPainel($c['gestora'], $c['tenant'], $c['ana']), $dados['total']);
    }

    #[TestDox('TELA: o número da coluna Relatório é um botão com a URL do modal (filho direto da célula); zero não é botão')]
    public function testTelaDoPainel(): void
    {
        $c = $this->cenario();
        static::getContainer()->get(PreferenciaDoUsuarioRepository::class)
            ->gravar($c['tenant'], $c['gestora'], CatalogoDePreferenciasDoDashboard::COLUNAS_EXTRAS, ['relatorio']);
        $this->entrar($c['gestora'], $c['tenant']);

        $crawler = $this->client->request('GET', '/dashboard?data_de=' . self::DE . '&data_ate=' . self::ATE);
        self::assertResponseIsSuccessful();

        $linha = fn (string $nome): Crawler => $crawler->filter('.db-table-card table.db-table > tbody > tr')->reduce(
            static fn (Crawler $tr): bool => str_contains($tr->filter('.db-colab-nome')->attr('title') ?? '', $nome),
        );

        $botao = $linha('Ana Equipe')->filter('td[data-coluna="relatorio"] > button.db-num-link, td[data-coluna="relatorio"] > .db-cel > button.db-num-link');
        self::assertCount(1, $botao);
        self::assertSame('4', trim($botao->text()));
        self::assertSame('button', $botao->attr('type'));
        self::assertSame('Ana Equipe', $botao->attr('data-db-relatorio-nome'));
        $url = (string) $botao->attr('data-db-relatorio-url');
        self::assertSame('/dashboard/relatorio/' . $c['ana']->getId(), parse_url($url, PHP_URL_PATH));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        self::assertSame(['data_de' => self::DE, 'data_ate' => self::ATE], $q);
        self::assertCount(0, $linha('Ana Equipe')->filter('td[data-coluna="relatorio"] a'));

        // Sem registro no período: número sem botão, mas a URL fica para o menu do botão direito.
        $zero = $linha('Gestora Relatorio')->filter('td[data-coluna="relatorio"] [data-db-relatorio-url]');
        self::assertCount(1, $zero);
        self::assertSame('span', $zero->nodeName());
        self::assertCount(0, $linha('Gestora Relatorio')->filter('td[data-coluna="relatorio"] button'));

        // O Total do Relatório não abre modal.
        self::assertCount(0, $crawler->filter('table.db-table > tfoot td[data-coluna="relatorio"] button, table.db-table > tfoot td[data-coluna="relatorio"] a'));
    }
}
