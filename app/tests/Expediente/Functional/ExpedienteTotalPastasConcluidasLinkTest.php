<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Functional;

use App\Dashboard\Entity\DashboardZeramento;
use App\Dashboard\Preferencia\CatalogoDePreferenciasDoDashboard;
use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Cargo;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * O Total de "Pastas concluídas" do Dashboard vira link para o Expediente com `responsavel=equipe` (ou
 * `equipe:<cargo>`, `equipe:__sem__`). O Total soma só as linhas exibidas, isto é, colaboradores ATIVOS; a lista
 * com `equipe` conta o mesmo número (paridade, regra B12), com pasta oculta pelo cadeado (D1) e responsável que
 * saiu do escritório fora dos dois lados.
 */
#[CoversClass(PastaRepository::class)]
final class ExpedienteTotalPastasConcluidasLinkTest extends JusPrimeWebTestCase
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

    private function darCargo(User $user, Tenant $tenant, string $nome): void
    {
        $cargo = new Cargo();
        $cargo->setNome($nome);
        $cargo->setTenant($tenant);
        $this->em()->persist($cargo);
        $ut = $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $user, 'tenant' => $tenant]);
        $ut->setCargo($cargo);
        $this->em()->flush();
    }

    private function concluir(Pasta $pasta, ?User $responsavel, string $quando = '2026-09-10 10:00'): Pasta
    {
        $pasta->setResponsavel($responsavel);
        $pasta->setFinalizadaEm(new \DateTimeImmutable($quando));
        $this->em()->flush();

        return $pasta;
    }

    /**
     * Advogado: Ana 2 + Bruno 1. Estagiário: Carla 1. Sem cargo: Davi 1 (e a gestora, 0). Eva saiu do escritório (1,
     * fora). Uma pasta concluída sem responsável (fora). Uma pasta de Ana oculta pelo cadeado para a gestora (só o
     * primário a vê). Uma de Ana fora do período e uma na lápide.
     *
     * @return array<string, mixed>
     */
    private function cenario(): array
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $gestora = $this->criarColaborador($tenant, 'Gestora Total', self::PERMISSOES_GESTOR);
        $ana     = $this->criarColaborador($tenant, 'Ana Equipe');
        $bruno   = $this->criarColaborador($tenant, 'Bruno Equipe');
        $carla   = $this->criarColaborador($tenant, 'Carla Equipe');
        $davi    = $this->criarColaborador($tenant, 'Davi Equipe');
        $eva     = $this->criarColaborador($tenant, 'Eva Saiu');
        $this->darCargo($ana, $tenant, 'Advogado');
        $this->darCargo($bruno, $tenant, 'Advogado');
        $this->darCargo($carla, $tenant, 'Estagiário');

        $this->concluir($this->criarPasta($tenant), $ana);
        $this->concluir($this->criarPasta($tenant), $ana);
        $this->concluir($this->criarPasta($tenant), $bruno);
        $this->concluir($this->criarPasta($tenant), $carla);
        $this->concluir($this->criarPasta($tenant), $davi);
        $this->concluir($this->criarPasta($tenant), $eva);
        $this->concluir($this->criarPasta($tenant), null);
        $this->concluir($this->criarPasta($tenant), $ana, '2026-08-15 10:00');
        $lapide = $this->concluir($this->criarPasta($tenant), $ana);
        $lapide->marcarExcluida($primario, new \DateTimeImmutable());
        $oculta = $this->concluir($this->criarPasta($tenant), $ana);
        $this->em()->flush();
        $this->trancar($oculta, $primario, [[$ana, NivelDeAcesso::Editar]]);

        $ut = $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $eva, 'tenant' => $tenant]);
        (new \ReflectionProperty(UserTenant::class, 'isActive'))->setValue($ut, false);
        $this->em()->flush();

        static::getContainer()->get(PreferenciaDoUsuarioRepository::class)
            ->gravar($tenant, $gestora, CatalogoDePreferenciasDoDashboard::COLUNAS_EXTRAS, ['pastas_concluidas']);

        return compact('primario', 'tenant', 'gestora', 'ana', 'bruno', 'carla', 'davi', 'eva');
    }

    /** @param array<string, string> $query */
    private function painel(User $quem, Tenant $tenant, array $query = []): Crawler
    {
        $this->entrar($quem, $tenant);
        $crawler = $this->client->request('GET', '/dashboard?' . http_build_query($query + ['data_de' => self::DE, 'data_ate' => self::ATE]));
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function totalDaTela(Crawler $painel): Crawler
    {
        return $painel->filter('.db-table-card table.db-table > tfoot td[data-coluna="pastas_concluidas"] .db-total-cel > .db-total-num');
    }

    /** Quantas pastas a lista aberta pelo href do Total mostra. */
    private function tamanhoDaLista(Crawler $link): int
    {
        parse_str((string) parse_url((string) $link->attr('href'), PHP_URL_QUERY), $q);
        self::assertSame('/expediente', parse_url((string) $link->attr('href'), PHP_URL_PATH));
        unset($q['painel']);
        $crawler = $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral?' . http_build_query($q));
        self::assertResponseIsSuccessful();

        return $crawler->filter('#tabelaPastas tbody tr.pasta-row-link')->count();
    }

    #[TestDox('PARIDADE: Total do Painel = lista do link para a equipe toda, com cadeado e quem saiu fora dos dois lados')]
    public function testParidadeDaEquipe(): void
    {
        $c = $this->cenario();

        $painel = $this->painel($c['gestora'], $c['tenant']);
        $total = $this->totalDaTela($painel);
        self::assertSame('a', $total->nodeName());
        self::assertSame('5', trim($total->text()), 'Ana 2 (a oculta e a lápide não contam), Bruno 1, Carla 1, Davi 1');
        self::assertStringContainsString('equipe', (string) $total->attr('title'));
        self::assertSame($total->attr('title'), $total->attr('aria-label'));
        self::assertSame(5, $this->tamanhoDaLista($total));

        parse_str((string) parse_url((string) $total->attr('href'), PHP_URL_QUERY), $q);
        self::assertSame(['painel' => 'acervo-geral', 'concluida' => '1', 'responsavel' => 'equipe', 'data_de' => self::DE, 'data_ate' => self::ATE], $q);

        // O primário vê a oculta: Painel e lista sobem juntos (6). Eva e a sem responsável seguem fora.
        $this->entrar($c['primario'], $c['tenant']);
        static::getContainer()->get(PreferenciaDoUsuarioRepository::class)
            ->gravar($c['tenant'], $c['primario'], CatalogoDePreferenciasDoDashboard::COLUNAS_EXTRAS, ['pastas_concluidas']);
        $totalPrimario = $this->totalDaTela($this->painel($c['primario'], $c['tenant']));
        self::assertSame('6', trim($totalPrimario->text()));
        self::assertSame(6, $this->tamanhoDaLista($totalPrimario));
    }

    #[TestDox('PARIDADE: com o filtro de cargo o link é equipe:<cargo> e a lista conta o mesmo que o Total')]
    public function testParidadePorCargo(): void
    {
        $c = $this->cenario();

        $total = $this->totalDaTela($this->painel($c['gestora'], $c['tenant'], ['cargo' => 'Advogado']));
        self::assertSame('3', trim($total->text()));
        self::assertSame(3, $this->tamanhoDaLista($total));
        parse_str((string) parse_url((string) $total->attr('href'), PHP_URL_QUERY), $q);
        self::assertSame('equipe:Advogado', $q['responsavel']);
        self::assertStringContainsString('Advogado', (string) $total->attr('title'));

        $estagio = $this->totalDaTela($this->painel($c['gestora'], $c['tenant'], ['cargo' => 'Estagiário']));
        self::assertSame('1', trim($estagio->text()));
        self::assertSame(1, $this->tamanhoDaLista($estagio));
    }

    #[TestDox('PARIDADE: "sem cargo" (__sem__) inclui Davi e a gestora, e deixa de fora quem saiu e a pasta sem responsável')]
    public function testParidadeSemCargo(): void
    {
        $c = $this->cenario();

        $total = $this->totalDaTela($this->painel($c['gestora'], $c['tenant'], ['cargo' => '__sem__']));
        self::assertSame('1', trim($total->text()));
        self::assertSame(1, $this->tamanhoDaLista($total));
        parse_str((string) parse_url((string) $total->attr('href'), PHP_URL_QUERY), $q);
        self::assertSame('equipe:__sem__', $q['responsavel']);
    }

    #[TestDox('uma pessoa só na tabela: o Total usa o destino da pessoa; busca ativa e Total 0 ficam sem link')]
    public function testUmaPessoaBuscaEZero(): void
    {
        $c = $this->cenario();

        $pessoa = $this->totalDaTela($this->painel($c['gestora'], $c['tenant'], ['responsavel' => (string) $c['ana']->getId()]));
        self::assertSame('a', $pessoa->nodeName());
        self::assertSame('2', trim($pessoa->text()));
        self::assertSame(2, $this->tamanhoDaLista($pessoa));
        parse_str((string) parse_url((string) $pessoa->attr('href'), PHP_URL_QUERY), $q);
        self::assertSame((string) $c['ana']->getId(), $q['responsavel']);

        $busca = $this->totalDaTela($this->painel($c['gestora'], $c['tenant'], ['busca' => 'Equipe']));
        self::assertSame('span', $busca->nodeName(), 'busca filtra as linhas: a lista não teria como contar o mesmo');

        $zero = $this->totalDaTela($this->painel($c['gestora'], $c['tenant'], ['data_de' => '2025-01-01', 'data_ate' => '2025-01-31']));
        self::assertSame('span', $zero->nodeName());
        self::assertSame('0', trim($zero->text()));
    }

    #[TestDox('Zerar ativo na coluna: o Total volta a ser número sem link (o Expediente não aplica o corte)')]
    public function testZerarTiraOLink(): void
    {
        $c = $this->cenario();
        $this->em()->persist(new DashboardZeramento($c['tenant'], 'pastas_concluidas', $c['ana'], new \DateTimeImmutable('2026-09-05'), $c['primario']));
        $this->em()->flush();

        $total = $this->totalDaTela($this->painel($c['gestora'], $c['tenant']));
        self::assertSame('span', $total->nodeName());
    }

    #[TestDox('o select de responsável do Expediente ganha a opção da equipe (rótulo inteiro) e o valor sobrevive')]
    public function testExpedienteMostraORotuloDaEquipe(): void
    {
        $c = $this->cenario();
        $this->entrar($c['gestora'], $c['tenant']);

        $crawler = $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral?' . http_build_query(['concluida' => '1', 'responsavel' => 'equipe:Advogado']));
        $opcao = $crawler->filter('select[name="responsavel"] option[data-equipe]');
        self::assertCount(1, $opcao);
        self::assertSame('equipe:Advogado', $opcao->attr('value'));
        self::assertNotNull($opcao->attr('selected'));
        self::assertSame('Equipe · Advogado', trim($opcao->text()));

        $sem = $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral?' . http_build_query(['concluida' => '1', 'responsavel' => 'equipe:__sem__']));
        self::assertSame('Equipe · Sem cargo', trim($sem->filter('select[name="responsavel"] option[data-equipe]')->text()));

        $pessoa = $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral?' . http_build_query(['responsavel' => (string) $c['ana']->getId()]));
        self::assertCount(0, $pessoa->filter('select[name="responsavel"] option[data-equipe]'));
    }

    #[TestDox('outro escritório: equipe:<cargo> nunca traz pasta de lá')]
    public function testOutroEscritorioNaoVaza(): void
    {
        $c = $this->cenario();
        [, $outro] = $this->criarEscritorio();
        $intruso = $this->criarColaborador($outro, 'Ana de Outro');
        $this->darCargo($intruso, $outro, 'Advogado');
        $nup = $this->concluir($this->criarPasta($outro), $intruso)->getNup();

        $this->entrar($c['gestora'], $c['tenant']);
        $crawler = $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral?' . http_build_query(['concluida' => '1', 'responsavel' => 'equipe:Advogado', 'data_de' => self::DE, 'data_ate' => self::ATE]));
        self::assertSame(3, $crawler->filter('#tabelaPastas tbody tr.pasta-row-link')->count());
        self::assertStringNotContainsString($nup, (string) $this->client->getResponse()->getContent());
    }
}
