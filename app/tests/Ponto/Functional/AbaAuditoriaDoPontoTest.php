<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\AuditoriaDoPontoController;
use App\Ponto\Repository\TrilhaDoPontoRepository;
use App\Ponto\Service\ChavesDoHmacDoPonto;
use App\Ponto\Service\GestaoDoPonto;
use App\Ponto\Service\TrilhaDoPonto;
use App\Ponto\UseCase\ListarTrilhaDoPontoUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Twig\Environment;

/**
 * Aba "Auditoria" da /ponto (spec `ponto-desenho-0811.md`, E9, P64; desenho 16 L222 a L225 e L758): quem vê (quem
 * gere o ponto pelo perfil, o primário e o Master; o colaborador não), o lugar da aba na barra (entre Equipe e
 * Configurações) e o painel: cabeçalho da conferência, tabela dos eventos, selo abreviado, vazio e páginas.
 */
#[CoversClass(AuditoriaDoPontoController::class)]
#[CoversClass(GestaoDoPonto::class)]
#[Group('ponto')]
final class AbaAuditoriaDoPontoTest extends JusPrimeWebTestCase
{
    use CenarioDeJustificativaTrait;

    private const BARRA = 'section.pe-abas > div.pe-abas-barra[role="tablist"]';
    private const PAINEL = 'section.pe-abas > div#ponto-painel-auditoria.pe-abas-painel[role="tabpanel"][data-carregar="/ponto/auditoria"]';

    private KernelBrowser $client;
    private Tenant $escritorio;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->escritorio = $this->escritorio('Escritório Auditoria');
    }

    #[TestDox('O painel: 200 para quem gere o ponto pelo perfil, o primário e o Master; 403 para o colaborador e para quem só gere usuários')]
    public function testQuemVeOPainel(): void
    {
        $esperado = [];
        foreach ($this->papeis() as $papel => [$pessoa, $ve]) {
            $this->pedir($pessoa, '/ponto/auditoria');
            $esperado[$papel] = [$ve ? 200 : 403, $this->client->getResponse()->getStatusCode()];
        }

        foreach ($esperado as $papel => [$quer, $veio]) {
            self::assertSame($quer, $veio, $papel);
        }
    }

    #[TestDox('Na /ponto, a aba e o painel a buscar aparecem para quem vê e somem para o colaborador')]
    public function testAbaNaTela(): void
    {
        foreach ($this->papeis() as $papel => [$pessoa, $ve]) {
            $tela = $this->pedir($pessoa, '/ponto/?aba=auditoria');
            self::assertResponseIsSuccessful($papel);

            self::assertCount($ve ? 1 : 0, $tela->filter(self::BARRA . ' > button#ponto-aba-auditoria[role="tab"]'), $papel);
            self::assertCount($ve ? 1 : 0, $tela->filter(self::PAINEL . ':not([hidden]):empty'), $papel . ': o endereço abre a aba');
            self::assertCount($ve ? 0 : 1, $tela->filter('section.pe-abas > div#ponto-regiao-pendencias:not([hidden])'), $papel . ': sem a aba, cai em Pendências');
        }
    }

    #[TestDox('Na barra, Auditoria fica entre Equipe e Configurações, com o escudo e sem número (desenho L758)')]
    public function testLugarNaBarra(): void
    {
        $tela = $this->abas(['veEquipe' => true, 'veAuditoria' => true, 'veConfiguracoes' => true, 'abaAberta' => 'auditoria']);

        $aba = $tela->filter(self::BARRA . ' > button#ponto-aba-equipe + button#ponto-aba-auditoria[role="tab"][aria-selected="true"][tabindex="0"][aria-controls="ponto-painel-auditoria"] + button#ponto-aba-configuracoes');
        self::assertCount(1, $aba);
        $auditoria = $tela->filter(self::BARRA . ' > button#ponto-aba-auditoria');
        self::assertCount(1, $auditoria->filter('button > i.bi.bi-shield-lock:first-child'));
        self::assertSame('Auditoria', trim($auditoria->text()));
        self::assertCount(0, $auditoria->filter('span.pe-aba-num'));
        self::assertCount(1, $tela->filter(self::PAINEL . ':not([hidden]):empty'));
        self::assertCount(1, $tela->filter('section.pe-abas > div#ponto-regiao-pendencias[hidden]'));

        $sem = $this->abas(['abaAberta' => 'auditoria']);
        self::assertCount(0, $sem->filter('#ponto-aba-auditoria, #ponto-painel-auditoria'));
        self::assertCount(1, $sem->filter(self::BARRA . ' > button#ponto-aba-pendencias[aria-selected="true"]'));
    }

    #[TestDox('Painel com segredo: cabeçalho "íntegra" com o escudo, tabela de 5 colunas, ação em negrito e selo de 8 caracteres')]
    public function testPainelIntegra(): void
    {
        $gestor = $this->gestorDoPonto();
        $this->comSegredo();
        $this->trilha()->registrar($this->escritorio, $gestor, 'batida_registrada', ['colaborador' => 'Ana', 'tipo' => 'entrada', 'data_hora' => '2026-03-10 08:01:00', 'home_office' => true]);

        $painel = $this->pedir($gestor, '/ponto/auditoria');

        $cabecalho = $painel->filter('body > div.pe-audit-cab.pe-audit-cab--integra[role="status"]');
        self::assertCount(1, $cabecalho);
        self::assertCount(1, $cabecalho->filter('div > i.bi.bi-shield-check:first-child'));
        self::assertSame('Cadeia de eventos íntegra: cada evento é selado com o anterior. Registros originais nunca são sobrescritos.', trim($cabecalho->text()));
        $tabela = $painel->filter('body > div.pe-audit-rolagem > table.pe-tab.pe-audit-tab');
        self::assertCount(1, $tabela);
        self::assertSame(['Data e hora', 'Autor', 'Ação', 'Detalhe', 'Selo'], $tabela->filter('table > thead > tr > th')->each(static fn (Crawler $th): string => $th->text()));
        $linha = $tabela->filter('table > tbody > tr');
        self::assertCount(1, $linha);
        self::assertSame('Batida registrada', $linha->filter('tr > td:nth-child(3) > b')->text());
        self::assertSame('Pessoa gestor', $linha->filter('tr > td:nth-child(2)')->text());
        self::assertSame('Ana: Entrada às 08:01:00 de 10/03/2026 · home office', $linha->filter('tr > td.pe-audit-detalhe:nth-child(4)')->text());
        $selo = $linha->filter('tr > td:nth-child(5) > code.pe-audit-selo');
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $selo->text());
        self::assertSame($selo->text(), substr((string) $selo->attr('title'), 0, 8), 'o selo inteiro no title');
        self::assertMatchesRegularExpression('#^\d{2}/\d{2}/\d{4}, \d{2}:\d{2}:\d{2}$#', $linha->filter('tr > td:first-child')->text());
        self::assertCount(0, $painel->filter('nav.pe-audit-paginas'), 'uma página só, sem navegação');
    }

    #[TestDox('Painel com a cadeia mexida: cabeçalho "alterada" com o número do evento')]
    public function testPainelAlterada(): void
    {
        $gestor = $this->gestorDoPonto();
        $this->comSegredo();
        $this->trilha()->registrar($this->escritorio, $gestor, 'batida_registrada', ['tipo' => 'entrada']);
        $this->em()->getConnection()->executeStatement("UPDATE ponto_auditoria SET acao = 'justificativa_abonada' WHERE tenant_id = ?", [$this->escritorio->getId()]);

        $cabecalho = $this->pedir($gestor, '/ponto/auditoria')->filter('body > div.pe-audit-cab.pe-audit-cab--alterada');

        self::assertCount(1, $cabecalho->filter('div > i.bi.bi-shield-exclamation:first-child'));
        self::assertSame('Atenção: a cadeia de eventos foi alterada. O primeiro evento que não confere é o nº 1.', trim($cabecalho->text()));
    }

    #[TestDox('Painel sem segredo no servidor: cabeçalho "não selada" e a linha mostra "sem selo"')]
    public function testPainelSemSegredo(): void
    {
        $gestor = $this->gestorDoPonto();
        $this->trilha()->registrar($this->escritorio, $gestor, 'batida_registrada', ['tipo' => 'entrada']);

        $painel = $this->pedir($gestor, '/ponto/auditoria');

        self::assertCount(1, $painel->filter('body > div.pe-audit-cab.pe-audit-cab--nao-selada > i.bi.bi-shield-slash:first-child'));
        self::assertSame('sem selo', $painel->filter('table > tbody > tr > td:nth-child(5) > span.pe-audit-sem-selo')->text());
    }

    #[TestDox('Painel com um evento gravado sem selo antes de um selado: cabeçalho "trecho sem selo" na cor de alerta, nunca o de íntegra')]
    public function testPainelTrechoSemSelo(): void
    {
        $gestor = $this->gestorDoPonto();
        $this->comSegredo();
        $container = static::getContainer();
        (new TrilhaDoPonto($container->get(TrilhaDoPontoRepository::class), new ChavesDoHmacDoPonto(null), $container->get(ClockInterface::class), new TokenStorage()))
            ->registrar($this->escritorio, $gestor, 'batida_registrada', ['tipo' => 'entrada']);
        $this->trilha()->registrar($this->escritorio, $gestor, 'batida_registrada', ['tipo' => 'saida']);

        $cabecalho = $this->pedir($gestor, '/ponto/auditoria')->filter('body > div.pe-audit-cab.pe-audit-cab--trecho-sem-selo[role="status"]');

        self::assertCount(1, $cabecalho->filter('div > i.bi.bi-shield-slash:first-child'));
        self::assertSame('O evento nº 1 está sem selo e não pode ser conferido. Os demais eventos conferem com o selo.', trim($cabecalho->text()));
    }

    #[TestDox('Painel vazio: o texto do desenho, sem tabela')]
    public function testPainelVazio(): void
    {
        $painel = $this->pedir($this->gestorDoPonto(), '/ponto/auditoria');

        self::assertSame('Os eventos aparecem aqui a partir do próximo registro.', $painel->filter('body > div.pe-abas-vazio')->text());
        self::assertCount(0, $painel->filter('table'));
    }

    #[TestDox('Mais de 300 eventos (a página do desenho): "Mais recentes" e "Mais antigos" pelo endereço do painel; a 2 traz o mais antigo')]
    public function testPaginas(): void
    {
        $gestor = $this->gestorDoPonto();
        self::assertSame(300, ListarTrilhaDoPontoUseCase::POR_PAGINA, 'desenho 16, inventário 2.11 (L313)');
        for ($i = 0; $i < 301; ++$i) {
            $this->trilha()->registrar($this->escritorio, $gestor, 'batida_registrada', ['n' => $i]);
        }

        $primeira = $this->pedir($gestor, '/ponto/auditoria');
        self::assertCount(300, $primeira->filter('table > tbody > tr'));
        $nav = $primeira->filter('body > nav.pe-audit-paginas');
        self::assertCount(1, $nav->filter('nav > button.pe-audit-pag[data-auditoria-pagina="/ponto/auditoria?pagina=0"][disabled]:first-child'));
        self::assertCount(1, $nav->filter('nav > button.pe-audit-pag[data-auditoria-pagina="/ponto/auditoria?pagina=2"]:not([disabled]):last-child'));
        self::assertSame('Página 1 de 2 (301 eventos)', $nav->filter('nav > span.pe-audit-pag-txt')->text());

        $segunda = $this->pedir($gestor, '/ponto/auditoria?pagina=2');
        self::assertCount(1, $segunda->filter('table > tbody > tr'));
    }

    /** @return array<string, array{User, bool}> */
    private function papeis(): array
    {
        $master = $this->pessoa('master', ['ROLE_USER', 'ROLE_SUPER_ADMIN']);
        $this->vincularColaborador($master, $this->escritorio);
        $primario = $this->pessoa('primario');
        $this->vincularPrimario($primario, $this->escritorio);
        $soUsuarios = $this->pessoa('usuarios');
        $this->vincularGestor($soUsuarios, $this->escritorio);
        $colaborador = $this->pessoa('colaborador');
        $this->vincularColaborador($colaborador, $this->escritorio);

        return [
            'gestor do ponto pelo perfil' => [$this->gestorDoPonto(), true],
            'primário'                    => [$primario, true],
            'Master'                      => [$master, true],
            'só gere usuários'            => [$soUsuarios, false],
            'colaborador'                 => [$colaborador, false],
        ];
    }

    private function gestorDoPonto(): User
    {
        $gestor = $this->pessoa('gestor');
        $this->vincular($gestor, $this->escritorio, ['modules.ponto.view', GestaoDoPonto::AUDITORIA], false);

        return $gestor;
    }

    /** Troca a chave do servidor antes da primeira busca (o container de teste aceita a troca de serviço ainda não usado). */
    private function comSegredo(): void
    {
        static::getContainer()->set(ChavesDoHmacDoPonto::class, new ChavesDoHmacDoPonto('segredo-da-aba'));
    }

    private function trilha(): TrilhaDoPonto
    {
        return static::getContainer()->get(TrilhaDoPonto::class);
    }

    private function pedir(User $quem, string $endereco): Crawler
    {
        $this->logarComTenant($this->client, $quem, $this->escritorio);

        return $this->client->request('GET', $endereco);
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
