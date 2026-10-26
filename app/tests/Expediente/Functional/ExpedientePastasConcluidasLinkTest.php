<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Functional;

use App\Dashboard\Controller\DashboardController;
use App\Dashboard\Preferencia\CatalogoDePreferenciasDoDashboard;
use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Dashboard\UseCase\ObterDadosDashboardUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Expediente\Controller\ExpedienteController;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Pastas concluídas: o número da coluna do Dashboard vira link para o Acervo do Expediente com
 * `concluida=1` (regra B12: o número clicado = o tamanho da lista). O Relatório NÃO vira link: conta
 * registros, e a lista conta pastas.
 */
#[CoversClass(ExpedienteController::class)]
#[CoversClass(PastaRepository::class)]
#[CoversClass(DashboardController::class)]
final class ExpedientePastasConcluidasLinkTest extends JusPrimeWebTestCase
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

    private function concluir(Pasta $pasta, User $responsavel, string $quando): Pasta
    {
        $pasta->setResponsavel($responsavel);
        $pasta->setFinalizadaEm(new \DateTimeImmutable($quando));
        $this->em()->flush();

        return $pasta;
    }

    /**
     * Ana: 2 livres no período + 1 arquivada no período (conta: qualquer situação) + 1 fora do período + 1 lápide +
     * 1 oculta pelo cadeado (a gestora está fora da grade) + 1 aberta (nunca finalizada). Bruno: 1. Outro escritório: 1
     * da "Ana de lá" (pessoa de outro tenant).
     *
     * @return array{primario: User, tenant: Tenant, gestora: User, ana: User, bruno: User, intruso: User, nups: array<string, string>}
     */
    private function cenario(): array
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $gestora = $this->criarColaborador($tenant, 'Gestora Concluidas', self::PERMISSOES_GESTOR);
        $ana     = $this->criarColaborador($tenant, 'Ana Equipe');
        $bruno   = $this->criarColaborador($tenant, 'Bruno Equipe');

        $nups = [];
        $nups['livre1'] = $this->concluir($this->criarPasta($tenant), $ana, '2026-09-05 10:00')->getNup();
        $nups['livre2'] = $this->concluir($this->criarPasta($tenant), $ana, '2026-09-30 23:00')->getNup(); // última hora do período
        $arquivada = $this->concluir($this->criarPasta($tenant), $ana, '2026-09-08 10:00');
        $arquivada->setSituacao(Pasta::SITUACAO_ARQUIVADA);
        $nups['arquivada'] = $arquivada->getNup();
        $nups['fora']      = $this->concluir($this->criarPasta($tenant), $ana, '2026-08-31 23:59')->getNup();
        $lapide = $this->concluir($this->criarPasta($tenant), $ana, '2026-09-10 10:00');
        $lapide->marcarExcluida($primario, new \DateTimeImmutable());
        $nups['lapide'] = $lapide->getNup();
        $oculta = $this->concluir($this->criarPasta($tenant), $ana, '2026-09-07 10:00');
        $nups['oculta'] = $oculta->getNup();
        $nups['aberta'] = $this->criarPasta($tenant)->getNup();
        $aberta = $this->em()->getRepository(Pasta::class)->findOneBy(['nup' => $nups['aberta']]);
        $aberta->setResponsavel($ana);
        $nups['bruno'] = $this->concluir($this->criarPasta($tenant), $bruno, '2026-09-06 10:00')->getNup();

        [, $outro] = $this->criarEscritorio();
        $intruso = $this->criarColaborador($outro, 'Ana de Outro');
        $nups['outro'] = $this->concluir($this->criarPasta($outro), $intruso, '2026-09-05 10:00')->getNup();

        $this->em()->flush();
        $this->trancar($oculta, $primario, [[$ana, NivelDeAcesso::Editar]]);

        return ['primario' => $primario, 'tenant' => $tenant, 'gestora' => $gestora, 'ana' => $ana, 'bruno' => $bruno, 'intruso' => $intruso, 'nups' => $nups];
    }

    private function numeroDoPainel(User $quemOlha, Tenant $tenant, User $pessoa): int
    {
        $ocultas = static::getContainer()->get(AcessoAPasta::class)->idsOcultosPara($quemOlha, (int) $tenant->getId());
        $saida   = static::getContainer()->get(ObterDadosDashboardUseCase::class)->executar(
            $tenant,
            new \DateTimeImmutable('2026-10-26'),
            ['data_de' => self::DE, 'data_ate' => self::ATE],
            ['pastas_concluidas'],
            $ocultas,
        );
        foreach ($saida->porAdvogado as $linha) {
            if ($linha->userId === $pessoa->getId()) {
                return (int) ($linha->extras['pastas_concluidas'] ?? 0);
            }
        }
        self::fail('pessoa sem linha no painel');
    }

    /** @return list<string> nups da lista aberta pelo link */
    private function listaDoLink(User $quemOlha, Tenant $tenant, array $query): array
    {
        $this->entrar($quemOlha, $tenant);
        $crawler = $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral?' . http_build_query($query));
        self::assertResponseIsSuccessful();

        return $crawler->filter('#tabelaPastas tbody tr.pasta-row-link td:first-child')
            ->each(static fn (Crawler $td): string => trim($td->text()));
    }

    #[TestDox('PARIDADE: o número da célula do Painel = o total da lista do link, para quem vê tudo e para quem tem pasta oculta')]
    public function testParidadeComOCadeado(): void
    {
        $c = $this->cenario();
        $query = ['concluida' => '1', 'responsavel' => $c['ana']->getId(), 'data_de' => self::DE, 'data_ate' => self::ATE];

        // Gestora fora da grade: a oculta não conta no painel nem aparece na lista.
        $nups = $this->listaDoLink($c['gestora'], $c['tenant'], $query);
        self::assertSame(3, $this->numeroDoPainel($c['gestora'], $c['tenant'], $c['ana']));
        self::assertCount($this->numeroDoPainel($c['gestora'], $c['tenant'], $c['ana']), $nups);
        self::assertEqualsCanonicalizing([$c['nups']['livre1'], $c['nups']['livre2'], $c['nups']['arquivada']], $nups);
        foreach (['fora', 'lapide', 'oculta', 'aberta', 'bruno', 'outro'] as $chave) {
            self::assertNotContains($c['nups'][$chave], $nups, $chave);
        }

        // O primário vê a oculta: painel e lista sobem juntos.
        $nupsPrimario = $this->listaDoLink($c['primario'], $c['tenant'], $query);
        self::assertSame(4, $this->numeroDoPainel($c['primario'], $c['tenant'], $c['ana']));
        self::assertCount(4, $nupsPrimario);
        self::assertContains($c['nups']['oculta'], $nupsPrimario);
    }

    #[TestDox('sem datas lista toda a conclusão da pessoa; as datas valem sobre a conclusão, não sobre a abertura')]
    public function testSemDatasEDatasSobreAConclusao(): void
    {
        $c = $this->cenario();

        $todas = $this->listaDoLink($c['gestora'], $c['tenant'], ['concluida' => '1', 'responsavel' => $c['ana']->getId()]);
        self::assertContains($c['nups']['fora'], $todas, 'sem datas, a de agosto entra');
        self::assertNotContains($c['nups']['aberta'], $todas, 'pasta nunca finalizada não é concluída');

        // Abertas hoje (2026) e concluídas em 2026-09: filtro só por data de abertura não as acharia no período.
        $soAgosto = $this->listaDoLink($c['gestora'], $c['tenant'], ['concluida' => '1', 'responsavel' => $c['ana']->getId(), 'data_de' => '2026-08-01', 'data_ate' => '2026-08-31']);
        self::assertSame([$c['nups']['fora']], $soAgosto);
    }

    #[TestDox('sem concluida=1 o filtro de datas segue sendo o da abertura (nada muda no Expediente)')]
    public function testSemOFiltroAsDatasSaoDaAbertura(): void
    {
        $c = $this->cenario();
        // Todas foram abertas "agora" (2026-10 ou depois): um período em setembro não acha nenhuma pela abertura.
        $nups = $this->listaDoLink($c['gestora'], $c['tenant'], ['responsavel' => $c['ana']->getId(), 'data_de' => self::DE, 'data_ate' => self::ATE]);
        self::assertSame([], $nups);
    }

    #[TestDox('outro escritório: responsavel de lá com concluida=1 não lista nada nem vaza a pasta de lá')]
    public function testOutroEscritorioNaoVaza(): void
    {
        $c = $this->cenario();

        $nups = $this->listaDoLink($c['gestora'], $c['tenant'], ['concluida' => '1', 'responsavel' => $c['intruso']->getId(), 'data_de' => self::DE, 'data_ate' => self::ATE]);
        self::assertSame([], $nups);
        self::assertStringNotContainsString($c['nups']['outro'], (string) $this->client->getResponse()->getContent());

        // E sem responsável: a lista do escritório não inclui a pasta de lá.
        $todas = $this->listaDoLink($c['gestora'], $c['tenant'], ['concluida' => '1']);
        self::assertNotContains($c['nups']['outro'], $todas);
    }

    #[TestDox('deep-link: concluida entra na allowlist e só o valor 1 liga o filtro')]
    public function testDeepLinkRepassaConcluida(): void
    {
        $c = $this->cenario();
        $this->entrar($c['gestora'], $c['tenant']);

        $crawler = $this->client->request('GET', '/expediente?' . http_build_query(['painel' => 'acervo-geral', 'concluida' => '1', 'responsavel' => '7', 'data_de' => self::DE]));
        parse_str((string) parse_url((string) $crawler->filter('#expediente-painel')->attr('data-painel-inicial'), PHP_URL_QUERY), $q);
        self::assertSame(['responsavel' => '7', 'data_de' => self::DE, 'concluida' => '1'], $q);

        // Qualquer outro valor é ignorado pelo filtro: a lista volta a ser a do acervo.
        $todas = $this->listaDoLink($c['gestora'], $c['tenant'], ['concluida' => 'sim', 'responsavel' => $c['ana']->getId()]);
        self::assertContains($c['nups']['aberta'], $todas);
    }

    #[TestDox('TELA: Pastas concluídas > 0 é link direto com href exato; zero não; Relatório e Total sem link')]
    public function testTelaDoPainel(): void
    {
        $c = $this->cenario();
        static::getContainer()->get(PreferenciaDoUsuarioRepository::class)
            ->gravar($c['tenant'], $c['gestora'], CatalogoDePreferenciasDoDashboard::COLUNAS_EXTRAS, ['pastas_concluidas', 'relatorio']);
        $this->entrar($c['gestora'], $c['tenant']);

        $crawler = $this->client->request('GET', '/dashboard?data_de=' . self::DE . '&data_ate=' . self::ATE);
        self::assertResponseIsSuccessful();

        $linha = fn (string $nome): Crawler => $crawler->filter('.db-table-card table.db-table > tbody > tr')->reduce(
            static fn (Crawler $tr): bool => str_contains($tr->filter('.db-colab-nome')->attr('title') ?? '', $nome),
        );

        $link = $linha('Ana Equipe')->filter('td[data-coluna="pastas_concluidas"] a.db-num-link');
        self::assertCount(1, $link);
        self::assertSame('3', trim($link->text()));
        parse_str((string) parse_url((string) $link->attr('href'), PHP_URL_QUERY), $q);
        self::assertSame('/expediente', parse_url((string) $link->attr('href'), PHP_URL_PATH));
        self::assertSame([
            'painel'      => 'acervo-geral',
            'concluida'   => '1',
            'responsavel' => (string) $c['ana']->getId(),
            'data_de'     => self::DE,
            'data_ate'    => self::ATE,
        ], $q);
        self::assertSame('Abrir: Pastas concluídas de Ana Equipe', $link->attr('title'));
        self::assertNull($link->attr('target'), 'abre na mesma aba, como os demais');

        // Quem não concluiu nada no período: número sem link.
        self::assertCount(0, $linha('Gestora Concluidas')->filter('td[data-coluna="pastas_concluidas"] a'));
        // Relatório (registros ≠ pastas) e o Total não são link.
        self::assertCount(0, $crawler->filter('table.db-table > tbody td[data-coluna="relatorio"] a'));
        self::assertCount(0, $crawler->filter('table.db-table > tfoot td[data-coluna="pastas_concluidas"] a'));
        self::assertCount(0, $crawler->filter('table.db-table > tfoot td[data-coluna="relatorio"] a'));
    }
}
