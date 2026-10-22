<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Functional;

use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\TrabalhoExterno\Controller\CurriculosController;
use App\TrabalhoExterno\Controller\VagasPublicasController;
use App\TrabalhoExterno\Entity\MetricaPublica;
use App\TrabalhoExterno\Repository\MetricaPublicaRepository;
use App\TrabalhoExterno\UseCase\ExportarAlertasCsvUseCase;
use App\TrabalhoExterno\UseCase\MontarPainelDeCurriculosUseCase;
use App\TrabalhoExterno\UseCase\RegistrarMetricaPublicaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * O painel do Master e os KPIs da página pública pela HTTP (spec VS 18.10.26 §4.9): o painel e o CSV dos
 * alertas são só do Master (a Equipe, o externo e a prévia levam 403); a visita conta 1 por GET de link
 * válido e nada sem token, com token desconhecido ou de robô; o clique em "Criar conta" é 204 e conta,
 * com o limite por IP.
 */
#[CoversClass(CurriculosController::class)]
#[CoversClass(VagasPublicasController::class)]
#[CoversClass(MontarPainelDeCurriculosUseCase::class)]
#[CoversClass(ExportarAlertasCsvUseCase::class)]
#[CoversClass(RegistrarMetricaPublicaUseCase::class)]
#[CoversClass(MetricaPublicaRepository::class)]
#[CoversClass(MetricaPublica::class)]
final class PainelDoTrabalhoExternoTest extends JusPrimeWebTestCase
{
    use CriaFixturesTrabalhoExternoTrait;

    private const PAINEL = '/trabalho-externo/api/curriculos/painel';
    private const CSV = '/trabalho-externo/curriculos/alertas.csv';
    private const NAVEGADOR = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->instalarCsrf($this->client);
    }

    #[TestDox('o painel é só do Master: 8 KPIs, 6 barras, crescimento e inscritos; Equipe, externo e prévia ?como= levam 403')]
    public function testSoOMasterVeOPainel(): void
    {
        [$sede, $a] = [$this->criarTenant('Sede'), $this->criarTenant('A')];
        $this->definirSede($sede);
        $master = $this->criarMaster($sede);
        $equipe = $this->criarUsuario($sede, nome: 'Equipe');
        $doA = $this->criarUsuario($a);
        $this->logarComTenant($this->client, $doA, $a);
        $this->postarJson($this->client, '/trabalho-externo/api/curriculos/meu', self::curriculo());
        self::assertResponseIsSuccessful();

        $this->logarComTenant($this->client, $master, $sede);
        $this->client->request('GET', self::PAINEL);
        self::assertResponseIsSuccessful();
        $p = $this->json($this->client);
        self::assertSame(['ativos', 'novos', 'oab', 'vol', 'vagas', 'alertas', 'compartilhadas', 'ofertas'], array_column($p['kpis'], 'k'));
        self::assertSame([1, 1, 1, 1], array_slice(array_column($p['kpis'], 'n'), 0, 4));
        self::assertSame(['mod' => 'cv', 'filtro' => ['cs' => 'oab']], $p['kpis'][2]['ir']);
        self::assertSame(['acao' => 'campanha'], $p['kpis'][5]['ir'], 'o clique em ALERTAS POR E-MAIL abre a campanha (§4.10)');
        self::assertCount(6, $p['barras']);
        self::assertSame([['rot' => 'Cível', 'n' => 1, 'cor' => '#0a6fa0', 'ir' => ['mod' => 'cv', 'filtro' => ['ca' => 'Cível']]]], $p['barras'][0]['itens']);
        $mes = (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m');
        self::assertSame([$mes => 1], $p['crescimento']['cvs']);
        self::assertSame([$mes => 1], $p['crescimento']['vols']);
        self::assertSame([], (array) $p['crescimento']['cands']);
        self::assertSame(0, $p['inscritos']);
        $this->client->request('GET', '/trabalho-externo/curriculos/exportar.csv?situacao=oab');
        self::assertCount(2, explode("\n", (string) $this->client->getResponse()->getContent()), 'o filtro "oab" do KPI COM OAB vale também no CSV da lista');
        $this->client->request('GET', '/trabalho-externo/curriculos/exportar.csv?situacao=est');
        self::assertCount(1, explode("\n", (string) $this->client->getResponse()->getContent()));

        $this->client->request('GET', self::PAINEL . '?como=equipe');
        self::assertResponseStatusCodeSame(403, 'a prévia não abre o painel');
        $this->logarComTenant($this->client, $equipe, $sede);
        $this->client->request('GET', self::PAINEL);
        self::assertResponseStatusCodeSame(403, 'a Equipe só vê a lista');
        self::assertSame('Estatísticas disponíveis só para o Master.', $this->json($this->client)['mensagem']);
        $this->logarComTenant($this->client, $doA, $a);
        $this->client->request('GET', self::PAINEL);
        self::assertResponseStatusCodeSame(403, 'o externo não vê o painel');
    }

    #[TestDox('CSV dos alertas: sem inscritos é 404 com o toast do desenho; com inscritos sai o arquivo do desenho; só o Master')]
    public function testCsvDosAlertas(): void
    {
        $sede = $this->criarTenant('Sede');
        $this->definirSede($sede);
        $master = $this->criarMaster($sede);
        $equipe = $this->criarUsuario($sede, nome: 'Equipe');

        $this->logarComTenant($this->client, $master, $sede);
        $this->client->request('GET', self::CSV);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(['ok' => false, 'titulo' => 'Ainda sem inscritos', 'mensagem' => 'Compartilhe vagas para começar a captar e-mails.'], $this->json($this->client));

        $this->client->request('GET', '/vagas-juridicas');
        $csrf = $this->client->getCrawler()->filter('#alertas')->attr('data-csrf');
        $this->client->request('POST', '/vagas-juridicas/alertas', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['email' => 'lead@teste.com', 'uf' => 'DF', 'areas' => ['Cível', 'Família'], 'lgpd' => true, '_token' => $csrf], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', self::CSV);
        self::assertResponseIsSuccessful();
        $resposta = $this->client->getResponse();
        self::assertStringContainsString('attachment; filename=alertas-vagas-bluejus.csv', (string) $resposta->headers->get('Content-Disposition'));
        self::assertStringStartsWith('text/csv', (string) $resposta->headers->get('Content-Type'));
        $linhas = explode("\n", (string) $resposta->getContent());
        self::assertSame("\u{FEFF}" . '"E-mail";"UF";"Áreas";"Inscrito em";"Origem"', $linhas[0]);
        self::assertStringStartsWith('"lead@teste.com";"DF";"Cível; Família";"', $linhas[1]);
        $this->client->request('GET', self::PAINEL);
        self::assertSame(1, $this->json($this->client)['inscritos']);

        $this->client->request('GET', self::CSV . '?como=equipe');
        self::assertResponseStatusCodeSame(403, 'a prévia não exporta');
        $this->logarComTenant($this->client, $equipe, $sede);
        $this->client->request('GET', self::CSV);
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('visita: 1 por GET de link válido; sem token, token desconhecido e robô não contam; o painel mostra visitas e cliques')]
    public function testVisitaPeloLink(): void
    {
        $sede = $this->criarTenant('Sede');
        $master = $this->criarMaster($sede);
        $this->logarComTenant($this->client, $master, $sede);
        $codigo = $this->postarJson($this->client, '/trabalho-externo/api/vagas', self::vaga())['codigo'];
        $caminho = (string) parse_url($this->postarJson($this->client, '/trabalho-externo/api/vagas/compartilhar', ['codigos' => [$codigo]])['url'], PHP_URL_PATH);
        $this->client->request('GET', '/logout');

        $this->abrir($caminho);
        self::assertResponseIsSuccessful();
        self::assertSame(['visitas' => 1, 'cliques' => 0], $this->totais());
        $this->abrir($caminho);
        self::assertSame(['visitas' => 2, 'cliques' => 0], $this->totais(), 'cada GET conta');
        foreach (['Mozilla/5.0 (compatible; Googlebot/2.1)', 'WhatsApp/2.23.20.0 A', 'facebookexternalhit/1.1', 'Slackbot-LinkExpanding 1.0', 'TelegramBot (like TwitterBot)'] as $robo) {
            $this->abrir($caminho, $robo);
            self::assertResponseIsSuccessful('o robô vê a página');
        }
        $this->abrir('/vagas-juridicas');
        $this->abrir('/vagas-juridicas/' . str_repeat('x', 43));
        self::assertSame(['visitas' => 2, 'cliques' => 0], $this->totais(), 'robô, página geral e token desconhecido não contam');

        $this->abrir($caminho, 'Mozilla/5.0 (compatible; Googlebot/2.1)');
        $cadastro = $this->client->getCrawler()->filter('a[href="/cadastro"]');
        self::assertGreaterThan(5, $cadastro->count());
        self::assertSame($cadastro->count(), $this->client->getCrawler()->filter('a[href="/cadastro"][data-url-cta="/vagas-juridicas/cta"]')->count(), 'todo CTA de cadastro leva o endereço do contador');

        $this->cta(['token' => substr($caminho, strlen('/vagas-juridicas/'))]);
        self::assertResponseStatusCodeSame(204);
        $this->logarComTenant($this->client, $master, $sede);
        $this->client->request('GET', self::PAINEL);
        self::assertSame('2 visita(s) pelo link · 1 clique(s) em criar conta · clique para campanha', $this->json($this->client)['kpis'][5]['sub']);
    }

    #[TestDox('clique em "Criar conta": 204 sem sessão nem CSRF, com e sem token; corpo inválido conta sem link; robô não conta')]
    public function testCliqueEmCriarConta(): void
    {
        $this->cta([]);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('', (string) $this->client->getResponse()->getContent());
        $this->cta(['token' => 'desconhecido-' . str_repeat('y', 30)]);
        $this->client->request('POST', '/vagas-juridicas/cta', [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_USER_AGENT' => self::NAVEGADOR, 'HTTP_ORIGIN' => 'http://localhost'], 'não é json');
        self::assertResponseStatusCodeSame(204);
        self::assertSame(['visitas' => 0, 'cliques' => 3], $this->totais());

        $this->cta([], 'Mozilla/5.0 (compatible; bingbot/2.0)');
        self::assertResponseStatusCodeSame(204);
        self::assertSame(['visitas' => 0, 'cliques' => 3], $this->totais(), 'robô não conta');
        $linhas = (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM trabalho_externo_metrica_publica WHERE chave_do_link = 0');
        self::assertSame(1, $linhas, 'um registro por dia e link, somado');
    }

    #[TestDox('o limite por IP do clique: estourado é 429 e não conta; a chave é o IP')]
    public function testLimitador(): void
    {
        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->disableReboot();
        $espiao = new RateLimiterFactoryEspiao(aceita: true);
        static::getContainer()->set('limiter.vagas_cta', $espiao);
        $client->request('POST', '/vagas-juridicas/cta', [], [], ['HTTP_USER_AGENT' => self::NAVEGADOR, 'HTTP_SEC_FETCH_SITE' => 'same-origin', 'REMOTE_ADDR' => '203.0.113.9'], '{}');
        self::assertResponseStatusCodeSame(204);
        self::assertSame(['203.0.113.9'], $espiao->chavesConsumidas);

        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('limiter.vagas_cta', new RateLimiterFactoryEspiao(aceita: false));
        $client->request('POST', '/vagas-juridicas/cta', [], [], ['HTTP_USER_AGENT' => self::NAVEGADOR, 'HTTP_SEC_FETCH_SITE' => 'same-origin'], '{}');
        self::assertResponseStatusCodeSame(429);
        self::assertSame(['visitas' => 0, 'cliques' => 1], static::getContainer()->get(MetricaPublicaRepository::class)->totais(), 'só o primeiro, aceito, contou');
    }

    #[TestDox('o clique só conta da própria página: Sec-Fetch-Site same-origin, ou Origin/Referer do mesmo host; o resto é 204 sem contar')]
    public function testCliqueSoDaPropriaOrigem(): void
    {
        $pedidos = [
            'outro site' => [['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_ORIGIN' => 'http://localhost'], 0],
            'same-site não basta' => [['HTTP_SEC_FETCH_SITE' => 'same-site'], 0],
            'sem nenhum cabeçalho de origem' => [[], 0],
            'Origin de outro host' => [['HTTP_ORIGIN' => 'https://malicioso.test'], 0],
            'Referer de outro host' => [['HTTP_REFERER' => 'https://malicioso.test/vagas-juridicas'], 0],
            'Referer da própria página' => [['HTTP_REFERER' => 'http://localhost/vagas-juridicas'], 1],
            'Sec-Fetch-Site same-origin' => [['HTTP_SEC_FETCH_SITE' => 'same-origin'], 1],
        ];
        $esperado = 0;
        foreach ($pedidos as $caso => [$cabecalhos, $conta]) {
            // Sem histórico: o BrowserKit mandaria o Referer da requisição anterior.
            $this->client->restart();
            $this->client->request('POST', '/vagas-juridicas/cta', [], [], $cabecalhos + ['HTTP_USER_AGENT' => self::NAVEGADOR], '{}');
            self::assertResponseStatusCodeSame(204, $caso);
            $esperado += $conta;
            self::assertSame($esperado, $this->totais()['cliques'], $caso);
        }
    }

    #[TestDox('visita acima do teto por IP e link: a página abre normalmente e não conta; a chave é IP|token')]
    public function testLimitadorDaVisita(): void
    {
        $sede = $this->criarTenant('Sede');
        $master = $this->criarMaster($sede);
        $this->logarComTenant($this->client, $master, $sede);
        $codigo = $this->postarJson($this->client, '/trabalho-externo/api/vagas', self::vaga())['codigo'];
        $caminho = (string) parse_url($this->postarJson($this->client, '/trabalho-externo/api/vagas/compartilhar', ['codigos' => [$codigo]])['url'], PHP_URL_PATH);
        $token = substr($caminho, strlen('/vagas-juridicas/'));

        $espiao = new RateLimiterFactoryEspiao(aceita: true);
        static::getContainer()->set('limiter.vagas_visita', $espiao);
        $this->client->request('GET', $caminho, [], [], ['HTTP_USER_AGENT' => self::NAVEGADOR, 'REMOTE_ADDR' => '198.51.100.7']);
        self::assertResponseIsSuccessful();
        self::assertSame(['198.51.100.7|' . $token], $espiao->chavesConsumidas);
        self::assertSame(1, $this->totais()['visitas']);

        // O limitador já foi criado neste kernel: o teto estourado vem num kernel novo (o banco é o mesmo).
        static::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->set('limiter.vagas_visita', new RateLimiterFactoryEspiao(aceita: false));
        $this->abrir($caminho);
        self::assertResponseIsSuccessful('acima do teto a página abre');
        self::assertStringContainsString('Advogado trabalhista pleno', (string) $this->client->getResponse()->getContent());
        self::assertSame(1, $this->totais()['visitas'], 'acima do teto não conta');
    }

    private function abrir(string $caminho, string $ua = self::NAVEGADOR): void
    {
        $this->client->request('GET', $caminho, [], [], ['HTTP_USER_AGENT' => $ua]);
    }

    /** @param array<string, mixed> $corpo */
    private function cta(array $corpo, string $ua = self::NAVEGADOR): void
    {
        $this->client->request('POST', '/vagas-juridicas/cta', [], [], ['CONTENT_TYPE' => 'text/plain;charset=UTF-8', 'HTTP_USER_AGENT' => $ua, 'HTTP_SEC_FETCH_SITE' => 'same-origin'], json_encode($corpo, JSON_THROW_ON_ERROR));
    }

    /** @return array{visitas: int, cliques: int} */
    private function totais(): array
    {
        return static::getContainer()->get(MetricaPublicaRepository::class)->totais();
    }

    /** @return array<string, mixed> */
    private static function curriculo(): array
    {
        return [
            'nome' => 'Ana Souza', 'email' => 'ana@exemplo.test', 'tel' => '(61) 99999-0000', 'situacao' => 'adv',
            'oabNum' => '12345', 'oabUf' => 'DF', 'cargo' => 'Advogado(a)', 'cidade' => 'Brasília', 'uf' => 'DF',
            'areas' => ['Cível'], 'resumo' => 'Atuação cível.', 'vol' => 'sim', 'lgpd' => true,
        ];
    }

    /** @return array<string, mixed> */
    private static function vaga(): array
    {
        return [
            'titulo' => 'Advogado trabalhista pleno', 'cargo' => 'Advogado(a)', 'contratacao' => 'CLT', 'modalidade' => 'Híbrido',
            'cidade' => 'Brasília', 'uf' => 'DF', 'areas' => ['Trabalhista'], 'ofertante' => ['nome' => 'Escritório'],
        ];
    }
}
