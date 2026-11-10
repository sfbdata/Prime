<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\ConfiguracoesDoPontoController;
use App\Ponto\Controller\EquipeDoPontoController;
use App\Ponto\Controller\PontoController;
use App\Ponto\Service\GestaoDoPonto;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

/**
 * Abas de gestão da /ponto (spec `docs/specs/ponto-desenho-0811.md`, E5 e E6; P58): a aba só aparece para quem a
 * vê, o painel vem vazio com o endereço da busca, e a rota do painel recusa quem não vê. Pela /ponto inteira
 * (PontoController::index): o número da Equipe, a aba aberta pelo endereço e a queda em Pendências de quem não vê.
 */
#[CoversClass(EquipeDoPontoController::class)]
#[CoversClass(ConfiguracoesDoPontoController::class)]
#[CoversClass(PontoController::class)]
#[Group('ponto')]
final class AbasDeGestaoDoPontoTest extends JusPrimeWebTestCase
{
    use CenarioDeJustificativaTrait;

    private const BARRA = 'section.pe-abas > div.pe-abas-barra[role="tablist"]';

    private KernelBrowser $client;
    private Tenant $escritorio;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->escritorio = $this->escritorio('Escritório Gestão');
    }

    #[TestDox('O painel da Equipe: 200 para o primário e para quem gere usuários pelo perfil, 403 para o colaborador')]
    public function testPainelDaEquipe(): void
    {
        $primario = $this->pessoa('primario');
        $this->vincularPrimario($primario, $this->escritorio);
        $gestor = $this->pessoa('gestor');
        $this->vincularGestor($gestor, $this->escritorio);
        $colaborador = $this->pessoa('colaborador');
        $this->vincularColaborador($colaborador, $this->escritorio);

        $this->pedir($primario, '/ponto/equipe');
        self::assertResponseIsSuccessful();
        $this->pedir($gestor, '/ponto/equipe');
        self::assertResponseIsSuccessful();
        $this->pedir($colaborador, '/ponto/equipe');
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('O painel das Configurações: 200 para quem gere o ponto pelo perfil, 403 para quem só gere usuários')]
    public function testPainelDasConfiguracoes(): void
    {
        $sedes = $this->pessoa('sedes');
        $this->vincular($sedes, $this->escritorio, ['modules.ponto.view', GestaoDoPonto::SEDES], false);
        $gestor = $this->pessoa('gestor');
        $this->vincularGestor($gestor, $this->escritorio);

        $this->pedir($sedes, '/ponto/configuracoes');
        self::assertResponseIsSuccessful();
        $this->pedir($gestor, '/ponto/configuracoes');
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Só os feriados (configurações do escritório) também abrem o painel das Configurações')]
    public function testConfiguracoesSoComFeriados(): void
    {
        $feriados = $this->pessoa('feriados');
        $this->vincular($feriados, $this->escritorio, ['modules.ponto.view', GestaoDoPonto::FERIADOS], false);

        $this->pedir($feriados, '/ponto/configuracoes');
        self::assertResponseIsSuccessful();
    }

    #[TestDox('Sem o módulo do ponto, a permissão de gestão não abre nenhum dos dois painéis')]
    public function testSemModuloDoPonto(): void
    {
        $semModulo = $this->pessoa('sem-modulo');
        $this->vincular($semModulo, $this->escritorio, [GestaoDoPonto::EQUIPE, GestaoDoPonto::SEDES], false);

        $this->pedir($semModulo, '/ponto/equipe');
        self::assertResponseStatusCodeSame(403);
        $this->pedir($semModulo, '/ponto/configuracoes');
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Quem gere o escritório A e está com a sessão no B, onde é colaborador, não abre a Equipe do B')]
    public function testGestorDeOutroEscritorio(): void
    {
        $outro = $this->escritorio('Escritório Vizinho');
        $gestor = $this->pessoa('gestor');
        $this->vincularGestor($gestor, $outro);
        $this->vincularColaborador($gestor, $this->escritorio);

        $this->pedir($gestor, '/ponto/equipe');
        self::assertResponseStatusCodeSame(403);
        $this->logarComTenant($this->client, $gestor, $outro);
        $this->client->request('GET', '/ponto/equipe');
        self::assertResponseIsSuccessful();
    }

    #[TestDox('Com permissão, as abas Equipe e Configurações entram depois de Minhas Justificativas, com o painel a buscar')]
    public function testAbasNaBarra(): void
    {
        $tela = $this->abas(['veEquipe' => true, 'veConfiguracoes' => true, 'equipeAAnalisar' => 3, 'abaAberta' => 'equipe']);

        $equipe = $tela->filter(self::BARRA . ' > button#ponto-aba-justificativas + button#ponto-aba-equipe[role="tab"][aria-selected="true"][tabindex="0"][aria-controls="ponto-painel-equipe"]');
        self::assertCount(1, $equipe, 'Equipe vem depois de Minhas Justificativas e abre pelo endereço');
        self::assertCount(1, $equipe->filter('button > i.bi.bi-people:first-child'));
        self::assertSame('Equipe3', trim($equipe->text()), 'o número é o das justificativas a analisar');
        self::assertCount(1, $equipe->filter('button > span.pe-aba-num.pe-aba-num--pend:last-child'));
        $config = $tela->filter(self::BARRA . ' > button#ponto-aba-equipe + button#ponto-aba-configuracoes[role="tab"][aria-selected="false"][tabindex="-1"]:last-child');
        self::assertCount(1, $config);
        self::assertCount(1, $config->filter('button > i.bi.bi-sliders:first-child'));
        self::assertSame('Configurações', trim($config->text()), 'sem número');

        self::assertCount(1, $tela->filter('section.pe-abas > div#ponto-painel-equipe.pe-abas-painel[role="tabpanel"][data-carregar="/ponto/equipe"]:not([hidden]):empty'));
        self::assertCount(1, $tela->filter('section.pe-abas > div#ponto-painel-configuracoes.pe-abas-painel[role="tabpanel"][data-carregar="/ponto/configuracoes"][hidden]:empty'));
        self::assertCount(1, $tela->filter('section.pe-abas > div#ponto-regiao-pendencias[hidden]'));
    }

    #[TestDox('Sem permissão, as abas de gestão não aparecem e o endereço delas cai em Pendências')]
    public function testSemPermissao(): void
    {
        foreach (['equipe', 'configuracoes'] as $aba) {
            $tela = $this->abas(['abaAberta' => $aba]);

            self::assertCount(2, $tela->filter(self::BARRA . ' > button[role="tab"]'));
            self::assertCount(0, $tela->filter('[data-carregar]'));
            self::assertCount(1, $tela->filter(self::BARRA . ' > button#ponto-aba-pendencias[aria-selected="true"]'));
            self::assertCount(1, $tela->filter('section.pe-abas > div#ponto-regiao-pendencias:not([hidden])'));
        }
    }

    #[TestDox('Na /ponto, quem gere vê Equipe, com o número das justificativas a analisar do escritório, e Configurações; o colaborador não')]
    public function testAbasDeGestaoNaTela(): void
    {
        $gestor = $this->gestorDoPontoEDaEquipe();
        $colaborador = $this->pessoa('colaborador');
        $this->vincularColaborador($colaborador, $this->escritorio);
        $dia = new \DateTimeImmutable('first day of this month');
        $this->justificativa($colaborador, $this->escritorio, $dia, 'pendente');
        $this->justificativa($colaborador, $this->escritorio, $dia->modify('+1 day'), 'pendente');
        $this->justificativa($colaborador, $this->escritorio, $dia, 'abonado');
        // A própria de quem gere pelo perfil (não é primário): ele não a analisa (P43 a), e ela não conta.
        $this->justificativa($gestor, $this->escritorio, $dia, 'pendente');
        $vizinho = $this->escritorio('Escritório Vizinho');
        $this->vincularColaborador($colaborador, $vizinho);
        $this->justificativa($colaborador, $vizinho, $dia, 'pendente');

        $tela = $this->tela($gestor, '/ponto/');

        $equipe = $tela->filter(self::BARRA . ' > button#ponto-aba-justificativas + button#ponto-aba-equipe[role="tab"][aria-selected="false"]');
        self::assertCount(1, $equipe, 'Equipe depois de Minhas Justificativas, fechada ao entrar');
        self::assertSame('Equipe2', trim($equipe->text()), 'só as em análise da equipe deste escritório');
        self::assertCount(1, $equipe->filter('button > span.pe-aba-num.pe-aba-num--pend:last-child'));
        self::assertCount(1, $tela->filter(self::BARRA . ' > button#ponto-aba-configuracoes[role="tab"][aria-selected="false"]:last-child'));
        self::assertCount(1, $tela->filter('section.pe-abas > div#ponto-painel-equipe[data-carregar="/ponto/equipe"][hidden]:empty'));
        self::assertCount(1, $tela->filter('section.pe-abas > div#ponto-painel-configuracoes[data-carregar="/ponto/configuracoes"][hidden]:empty'));
        self::assertCount(1, $tela->filter(self::BARRA . ' > button#ponto-aba-pendencias[aria-selected="true"]'));

        $tela = $this->tela($colaborador, '/ponto/');

        self::assertCount(0, $tela->filter('#ponto-aba-equipe, #ponto-aba-configuracoes, #ponto-painel-equipe, #ponto-painel-configuracoes'));
        self::assertCount(2, $tela->filter(self::BARRA . ' > button[role="tab"]'));
    }

    #[TestDox('Na /ponto, o primário vê a Equipe com a própria em análise no número (ele analisa a própria, P43 a)')]
    public function testNumeroDaEquipeDoPrimario(): void
    {
        $primario = $this->pessoa('primario');
        $this->vincularPrimario($primario, $this->escritorio);
        $colaborador = $this->pessoa('colaborador');
        $this->vincularColaborador($colaborador, $this->escritorio);
        $dia = new \DateTimeImmutable('first day of this month');
        $this->justificativa($colaborador, $this->escritorio, $dia, 'pendente');
        $this->justificativa($primario, $this->escritorio, $dia, 'pendente');

        $tela = $this->tela($primario, '/ponto/');

        self::assertSame('Equipe2', trim($tela->filter(self::BARRA . ' > button#ponto-aba-equipe')->text()));
        self::assertCount(1, $tela->filter(self::BARRA . ' > button#ponto-aba-configuracoes'));
    }

    #[TestDox('Na /ponto, ?aba=equipe e ?aba=configuracoes abrem a aba para quem a vê; para o colaborador, caem em Pendências')]
    public function testAbaDeGestaoPeloEndereco(): void
    {
        $gestor = $this->gestorDoPontoEDaEquipe();
        $colaborador = $this->pessoa('colaborador');
        $this->vincularColaborador($colaborador, $this->escritorio);

        foreach (['equipe', 'configuracoes'] as $aba) {
            $tela = $this->tela($gestor, '/ponto/?aba=' . $aba);
            self::assertCount(1, $tela->filter(self::BARRA . ' > button#ponto-aba-' . $aba . '[aria-selected="true"][tabindex="0"]'), $aba);
            self::assertCount(1, $tela->filter('section.pe-abas > div#ponto-painel-' . $aba . '[data-carregar]:not([hidden])'), $aba . ': o painel abre e é buscado');
            self::assertCount(1, $tela->filter('section.pe-abas > div#ponto-regiao-pendencias[hidden]'), $aba);
            self::assertCount(1, $tela->filter(self::BARRA . ' > button#ponto-aba-pendencias[aria-selected="false"]'), $aba);

            $tela = $this->tela($colaborador, '/ponto/?aba=' . $aba);
            self::assertCount(0, $tela->filter('#ponto-aba-' . $aba), $aba);
            self::assertCount(1, $tela->filter(self::BARRA . ' > button#ponto-aba-pendencias[aria-selected="true"][tabindex="0"]'), $aba . ': cai em Pendências');
            self::assertCount(1, $tela->filter('section.pe-abas > div#ponto-regiao-pendencias:not([hidden])'), $aba);
        }
    }

    #[TestDox('Na /ponto, quem só gere as sedes vê Configurações e não a Equipe (nem o endereço dela)')]
    public function testSoConfiguracoesNaTela(): void
    {
        $sedes = $this->pessoa('sedes');
        $this->vincular($sedes, $this->escritorio, ['modules.ponto.view', GestaoDoPonto::SEDES], false);

        $tela = $this->tela($sedes, '/ponto/?aba=equipe');

        self::assertCount(0, $tela->filter('#ponto-aba-equipe, #ponto-painel-equipe'));
        self::assertCount(1, $tela->filter(self::BARRA . ' > button#ponto-aba-configuracoes'));
        self::assertCount(1, $tela->filter(self::BARRA . ' > button#ponto-aba-pendencias[aria-selected="true"]'));
    }

    /** Gere a equipe (Equipe) e o ponto (Configurações e Auditoria) pelo perfil, sem ser primário. */
    private function gestorDoPontoEDaEquipe(): User
    {
        $gestor = $this->pessoa('gestor');
        $this->vincular($gestor, $this->escritorio, ['modules.ponto.view', GestaoDoPonto::EQUIPE, GestaoDoPonto::SEDES], false);

        return $gestor;
    }

    private function tela(User $quem, string $endereco): Crawler
    {
        $this->logarComTenant($this->client, $quem, $this->escritorio);
        $tela = $this->client->request('GET', $endereco);
        self::assertResponseIsSuccessful($endereco);

        return $tela;
    }

    private function pedir(User $quem, string $endereco): void
    {
        $this->logarComTenant($this->client, $quem, $this->escritorio);
        $this->client->request('GET', $endereco);
    }

    /** @param array<string, mixed> $variaveis */
    private function abas(array $variaveis): Crawler
    {
        $twig = static::getContainer()->get(Environment::class);
        $html = $twig->render('ponto/_abas.html.twig', $variaveis + [
            'pendencias'             => [],
            'justificativas'         => [],
            'abaAberta'              => 'pendencias',
            'podeAnalisarAPropria'   => false,
            'competenciaSelecionada' => '2026-11',
        ]);

        return new Crawler($html, 'http://localhost/ponto/');
    }
}
