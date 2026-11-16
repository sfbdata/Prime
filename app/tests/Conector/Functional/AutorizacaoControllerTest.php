<?php

declare(strict_types=1);

namespace App\Tests\Conector\Functional;

use App\Conector\Controller\AutorizacaoController;
use App\Conector\Entity\CodigoDoConector;
use App\Conector\Entity\ConcessaoDoConector;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Exception\AcessoDoConectorRecusado;
use App\Conector\Exception\ClienteRecusado;
use App\Conector\Service\ServidorDeAutorizacao;
use App\Conector\UseCase\ListarEscritoriosParaConectarUseCase;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tenant\Entity\PedidoDeExclusaoDeConta;
use App\Tenant\Service\BloqueioPorPedidoDeExclusao;
use App\Tests\Conector\Support\CenarioDaAutorizacaoTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A tela de autorização do conector pelo HTTP (spec mcp-remoto-oauth 3.2, 3.4, 4, 9.4 e 14.a; 12.3, 12.7 parte A,
 * 12.12, 12.24 e 12.27): o cliente recusado não redireciona; erro de protocolo volta ao app com `state` e `iss`; o POST
 * lê o pedido só da sessão, exige o CSRF e confere o escritório no servidor.
 */
#[CoversClass(AutorizacaoController::class)]
#[CoversClass(ListarEscritoriosParaConectarUseCase::class)]
final class AutorizacaoControllerTest extends JusPrimeWebTestCase
{
    use CenarioDaAutorizacaoTrait;

    private const FORM = 'autorizar_conexao';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    /** @return array{0: Tenant, 1: User} escritório liberado e pessoa logada com ele na sessão */
    private function cenario(): array
    {
        $this->clienteValido();
        $tenant = $this->escritorio();
        $pessoa = $this->pessoa();
        $this->vinculo($pessoa, $tenant);
        $this->liberarConector($tenant);
        $this->logarComTenant($this->client, $pessoa, $tenant);

        return [$tenant, $pessoa];
    }

    /** @param array<string, string|null> $trocas */
    private function abrir(array $trocas = []): Crawler
    {
        return $this->client->request('GET', '/oauth/authorize?' . http_build_query($this->pedidoValido($trocas)));
    }

    /**
     * Os campos que a tela manda no POST, lidos da tela (pedido e token CSRF).
     *
     * @return array<string, string>
     */
    private function campos(Crawler $tela, ?int $tenantId, bool $escrita = false, string $botao = 'autorizar'): array
    {
        $campos = [
            'pedido' => (string) $tela->filter('input[name="' . self::FORM . '[pedido]"]')->attr('value'),
            '_token' => (string) $tela->filter('input[name="' . self::FORM . '[_token]"]')->attr('value'),
            $botao => '',
        ];
        if ($tenantId !== null) {
            $campos['tenantId'] = (string) $tenantId;
        }
        if ($escrita) {
            $campos['escrita'] = '1';
        }

        return $campos;
    }

    /** @param array<string, string> $campos */
    private function enviar(array $campos, string $query = ''): void
    {
        $this->client->request('POST', '/oauth/authorize' . $query, [self::FORM => $campos]);
    }

    /** @return array<string, string> os parâmetros do redirecionamento ao app */
    private function voltaAoApp(string $redirect = self::REDIRECT): array
    {
        self::assertResponseStatusCodeSame(302);
        $local = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith($redirect . '?', $local);
        parse_str((string) parse_url($local, \PHP_URL_QUERY), $parametros);
        self::assertSame(ServidorDeAutorizacao::ISSUER, $parametros['iss'] ?? null, 'iss exato em todo redirecionamento');

        /** @var array<string, string> $parametros */
        return $parametros;
    }

    private function exigirPaginaDeErro(int $status, string $mensagem): void
    {
        self::assertResponseStatusCodeSame($status);
        self::assertFalse($this->client->getResponse()->headers->has('Location'), 'a recusa não redireciona');
        self::assertSelectorTextContains('main', $mensagem);
    }

    /** O escritório ou a pessoa recusado no POST volta ao app como "não autorizado", com state e iss. */
    private function exigirRecusaNoApp(): void
    {
        $parametros = $this->voltaAoApp();
        self::assertSame('access_denied', $parametros['error']);
        self::assertSame(AutorizacaoController::ACESSO_RECUSADO, $parametros['error_description']);
        self::assertSame('estado-do-app-123', $parametros['state']);
        self::assertArrayNotHasKey('code', $parametros);
    }

    private function concessoesDe(User $pessoa): int
    {
        return $this->em()->getRepository(ConcessaoDoConector::class)->count(['user' => $pessoa->getId()]);
    }

    #[TestDox('sem login, o /oauth/authorize manda ao login')]
    public function testSemLoginVaiAoLogin(): void
    {
        $this->clienteValido();

        $this->abrir();

        self::assertResponseRedirects('/login');
    }

    #[TestDox('12.12: a tela mostra o host do client_id (não o client_name) e o host do retorno; sem aviso de loopback')]
    public function testTelaMostraOHostDoCliente(): void
    {
        $this->cenario();

        $tela = $this->abrir();

        self::assertResponseIsSuccessful();
        $texto = $tela->filter('main')->text();
        self::assertStringContainsString('claude.ai', $texto);
        self::assertStringNotContainsString('Nome que o app diz ter', $texto);
        self::assertStringNotContainsString('próprio computador', $texto);
        self::assertSame("frame-ancestors 'none'", $this->client->getResponse()->headers->get('Content-Security-Policy'));
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertCount(0, $tela->filter('input[name="' . self::FORM . '[escrita]"]:checked'), 'gravação nasce desmarcada');
        self::assertCount(1, $tela->filter('input[name="' . self::FORM . '[escrita]"]'));
    }

    #[TestDox('retorno de loopback (Claude Code): a tela avisa; sem mcp:escrita pedido, a caixa de gravação não aparece')]
    public function testAvisoDeLoopback(): void
    {
        $this->cenario();

        $tela = $this->abrir(['redirect_uri' => self::LOOPBACK, 'scope' => 'mcp:leitura']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('127.0.0.1', $tela->filter('main')->text());
        self::assertStringContainsString('próprio computador', $tela->filter('main')->text());
        self::assertCount(0, $tela->filter('input[name="' . self::FORM . '[escrita]"]'));
    }

    /** @return iterable<string, array{array<string, string|null>}> */
    public static function clienteRecusado(): iterable
    {
        yield 'client_id desconhecido' => [['client_id' => 'https://claude.ai/outro-app']];
        yield 'redirect fora do documento' => [['redirect_uri' => 'https://evil.example/cb']];
        yield 'sem client_id' => [['client_id' => null]];
        yield 'sem redirect_uri' => [['redirect_uri' => null]];
    }

    /** @param array<string, string|null> $trocas */
    #[DataProvider('clienteRecusado')]
    #[TestDox('cliente ou retorno recusado ($_dataName): página de erro, sem redirecionar, mesmo com o PKCE errado')]
    public function testClienteRecusadoNaoRedireciona(array $trocas): void
    {
        $this->cenario();

        $this->abrir($trocas + ['code_challenge_method' => 'plain']);

        $this->exigirPaginaDeErro(400, ClienteRecusado::MENSAGEM);
    }

    /** @return iterable<string, array{array<string, string|null>, string}> */
    public static function erroDoProtocolo(): iterable
    {
        yield 'code_challenge_method ausente' => [['code_challenge_method' => null], 'invalid_request'];
        yield 'code_challenge_method plain' => [['code_challenge_method' => 'plain'], 'invalid_request'];
        yield 'resource ausente' => [['resource' => null], 'invalid_target'];
        yield 'resource diferente' => [['resource' => 'https://bluejus.com.br/outro'], 'invalid_target'];
        yield 'response_type token' => [['response_type' => 'token'], 'unsupported_response_type'];
    }

    /** @param array<string, string|null> $trocas */
    #[DataProvider('erroDoProtocolo')]
    #[TestDox('12.7: erro de protocolo ($_dataName) volta ao app com error, state e iss exato')]
    public function testErroDoProtocoloVoltaAoApp(array $trocas, string $erro): void
    {
        $this->cenario();

        $this->abrir($trocas);

        $parametros = $this->voltaAoApp();
        self::assertSame($erro, $parametros['error']);
        self::assertSame('estado-do-app-123', $parametros['state']);
        self::assertArrayNotHasKey('code', $parametros);
    }

    #[TestDox('autoriza: volta ao app com code, state e iss; o banco guarda o código pelo hash, no escritório escolhido')]
    public function testAutoriza(): void
    {
        [$tenant, $pessoa] = $this->cenario();
        $tela = $this->abrir();

        $this->enviar($this->campos($tela, (int) $tenant->getId(), escrita: true));

        $parametros = $this->voltaAoApp();
        self::assertSame('estado-do-app-123', $parametros['state']);
        $codigo = $this->em()->getRepository(CodigoDoConector::class)->findOneBy(['identificadorHash' => hash('sha256', $parametros['code'])]);
        self::assertInstanceOf(CodigoDoConector::class, $codigo);
        self::assertSame($tenant->getId(), $codigo->getTenant()?->getId());
        self::assertSame($pessoa->getId(), $codigo->getConcessao()->getUser()->getId());
        self::assertSame(['mcp:leitura', 'mcp:escrita', 'offline_access'], $codigo->getConcessao()->getEscopos());
    }

    #[TestDox('não autorizar: volta ao app com access_denied, state e iss, e nada é gravado')]
    public function testNaoAutorizar(): void
    {
        [$tenant, $pessoa] = $this->cenario();
        $tela = $this->abrir();

        $this->enviar($this->campos($tela, (int) $tenant->getId(), botao: 'negar'));

        $parametros = $this->voltaAoApp();
        self::assertSame('access_denied', $parametros['error']);
        self::assertSame('estado-do-app-123', $parametros['state']);
        self::assertSame(0, $this->concessoesDe($pessoa));
    }

    #[TestDox('12.27: POST sem token CSRF recusa e não grava')]
    public function testSemCsrfRecusa(): void
    {
        [$tenant, $pessoa] = $this->cenario();
        $campos = $this->campos($this->abrir(), (int) $tenant->getId());
        unset($campos['_token']);

        $this->enviar($campos);

        $this->exigirPaginaDeErro(400, AutorizacaoController::PEDIDO_INVALIDO);
        self::assertSame(0, $this->concessoesDe($pessoa));
    }

    #[TestDox('12.27: POST com token CSRF errado recusa e não grava')]
    public function testCsrfErradoRecusa(): void
    {
        [$tenant, $pessoa] = $this->cenario();
        $campos = $this->campos($this->abrir(), (int) $tenant->getId());
        $campos['_token'] = 'token-errado';

        $this->enviar($campos);

        $this->exigirPaginaDeErro(400, AutorizacaoController::PEDIDO_INVALIDO);
        self::assertSame(0, $this->concessoesDe($pessoa));
    }

    #[TestDox('12.27: escritório forjado no formulário, sem vínculo da pessoa (mesmo liberado), volta ao app com access_denied e não grava')]
    public function testEscritorioForjadoRecusa(): void
    {
        [, $pessoa] = $this->cenario();
        $alheio = $this->escritorio('Alheio');
        $this->liberarConector($alheio);

        $this->enviar($this->campos($this->abrir(), (int) $alheio->getId()));

        $this->exigirRecusaNoApp();
        self::assertSame(0, $this->concessoesDe($pessoa));
    }

    #[TestDox('autorizar sem escolher o escritório (dois disponíveis): volta à tela com o aviso, sem gastar o pedido')]
    public function testAutorizarSemEscolherVoltaATela(): void
    {
        $this->clienteValido();
        $a = $this->escritorio('A');
        $b = $this->escritorio('B');
        $pessoa = $this->pessoa();
        $this->vinculo($pessoa, $a);
        $this->vinculo($pessoa, $b);
        $this->liberarConector($a);
        $this->liberarConector($b);
        $this->logarComTenant($this->client, $pessoa, $a);
        $tela = $this->abrir();
        self::assertCount(0, $tela->filter('input[name="' . self::FORM . '[tenantId]"]:checked'), 'com dois disponíveis, nenhum vem marcado');
        $campos = $this->campos($tela, null);

        $novaTela = $this->client->request('POST', '/oauth/authorize', [self::FORM => $campos]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main [role="alert"]', AutorizacaoController::ESCOLHA_O_ESCRITORIO);
        self::assertSame($campos['pedido'], $novaTela->filter('input[name="' . self::FORM . '[pedido]"]')->attr('value'), 'o mesmo pedido');
        self::assertSame(0, $this->concessoesDe($pessoa));

        $this->enviar($this->campos($novaTela, (int) $b->getId()));

        $parametros = $this->voltaAoApp();
        self::assertArrayHasKey('code', $parametros);
        self::assertSame('estado-do-app-123', $parametros['state']);
    }

    #[TestDox('autorizar sem escolher o escritório com CSRF errado: o CSRF vem antes, recusa e gasta o pedido')]
    public function testSemEscolherComCsrfErradoRecusa(): void
    {
        [, $pessoa] = $this->cenario();
        $campos = $this->campos($this->abrir(), null);
        $campos['_token'] = 'token-errado';

        $this->enviar($campos);

        $this->exigirPaginaDeErro(400, AutorizacaoController::PEDIDO_INVALIDO);
        self::assertSame(0, $this->concessoesDe($pessoa));
    }

    #[TestDox('12.27: redirect_uri e state trocados no POST (query e corpo) não valem: o retorno usa o pedido da sessão')]
    public function testParametrosTrocadosNoPostNaoValem(): void
    {
        [$tenant] = $this->cenario();
        $campos = $this->campos($this->abrir(), (int) $tenant->getId());

        $this->client->request(
            'POST',
            '/oauth/authorize?' . http_build_query(['redirect_uri' => 'https://evil.example/cb', 'state' => 'trocado', 'client_id' => 'https://evil.example/app']),
            [self::FORM => $campos, 'redirect_uri' => 'https://evil.example/cb', 'state' => 'trocado', 'code_challenge' => str_repeat('b', 43)],
        );

        $parametros = $this->voltaAoApp(self::REDIRECT);
        self::assertSame('estado-do-app-123', $parametros['state']);
        $codigo = $this->em()->getRepository(CodigoDoConector::class)->findOneBy(['identificadorHash' => hash('sha256', $parametros['code'])]);
        self::assertSame(self::DESAFIO, $codigo?->getCodeChallenge());
        self::assertSame(self::REDIRECT, $codigo?->getRedirectUri());
    }

    #[TestDox('12.27: o pedido é de uso único: o mesmo POST de novo recusa')]
    public function testPedidoDeUsoUnico(): void
    {
        [$tenant, $pessoa] = $this->cenario();
        $campos = $this->campos($this->abrir(), (int) $tenant->getId());
        $this->enviar($campos);
        $this->voltaAoApp();

        $this->enviar($campos);

        $this->exigirPaginaDeErro(400, AutorizacaoController::PEDIDO_INVALIDO);
        self::assertSame(1, $this->concessoesDe($pessoa));
    }

    #[TestDox('sessão no escritório A autoriza o B (sem o filtro da sessão); a auditoria cai no B')]
    public function testSessaoEmAAutorizaB(): void
    {
        $this->clienteValido();
        $a = $this->escritorio('A');
        $b = $this->escritorio('B');
        $pessoa = $this->pessoa();
        $this->vinculo($pessoa, $a);
        $this->vinculo($pessoa, $b);
        $this->liberarConector($b);
        $this->logarComTenant($this->client, $pessoa, $a);

        $tela = $this->abrir();
        self::assertResponseIsSuccessful();
        self::assertCount(1, $tela->filter('input[name="' . self::FORM . '[tenantId]"][value="' . $b->getId() . '"]:not([disabled])'), 'B aparece, conectável');
        self::assertCount(1, $tela->filter('input[name="' . self::FORM . '[tenantId]"][value="' . $a->getId() . '"][disabled]'), 'A aparece desabilitado');
        $this->enviar($this->campos($tela, (int) $b->getId()));

        $parametros = $this->voltaAoApp();
        $codigo = $this->em()->getRepository(CodigoDoConector::class)->findOneBy(['identificadorHash' => hash('sha256', $parametros['code'])]);
        self::assertSame($b->getId(), $codigo?->getTenant()?->getId());
        self::assertSame(1, $this->em()->getRepository(AuditLog::class)->count(['entityClass' => ConsentimentoDoConector::class, 'tenantId' => $b->getId()]));
        self::assertSame(0, $this->em()->getRepository(AuditLog::class)->count(['entityClass' => ConsentimentoDoConector::class, 'tenantId' => $a->getId()]));
    }

    #[TestDox('sessão sem escritório (vários vínculos): a tela abre, sem 302 para tenant_selecionar')]
    public function testSessaoSemEscritorioNaoVaiASelecao(): void
    {
        $this->clienteValido();
        $a = $this->escritorio('A');
        $b = $this->escritorio('B');
        $pessoa = $this->pessoa();
        $this->vinculo($pessoa, $a);
        $this->vinculo($pessoa, $b);
        $this->liberarConector($a);
        $this->client->loginUser($pessoa);
        $this->marcarTermosAceitos($this->client);

        $tela = $this->abrir();

        self::assertResponseIsSuccessful();
        $this->enviar($this->campos($tela, (int) $a->getId()));
        self::assertArrayHasKey('code', $this->voltaAoApp());
    }

    #[TestDox('12.3: o primário de conta suspensa a pedido vê o motivo (a mensagem do pedido de exclusão) na tela, e o POST volta ao app com access_denied')]
    public function testPrimarioSuspensoAPedido(): void
    {
        $this->clienteValido();
        $tenant = $this->escritorio();
        $primario = $this->pessoa();
        $this->vinculo($primario, $tenant, sistema: true);
        $this->liberarConector($tenant);
        $this->em()->persist(new PedidoDeExclusaoDeConta($tenant, $primario, 'Não uso mais', new \DateTimeImmutable()));
        $this->em()->flush();
        $this->client->loginUser($primario);
        $this->marcarTermosAceitos($this->client);

        $tela = $this->abrir();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(BloqueioPorPedidoDeExclusao::MENSAGEM, $tela->filter('main')->text());

        $this->enviar($this->campos($tela, (int) $tenant->getId()));

        $this->exigirRecusaNoApp();
        self::assertSame(0, $this->concessoesDe($primario));
    }

    /** @return iterable<string, array{string}> */
    public static function semLiberacao(): iterable
    {
        yield 'chave geral desligada' => ['chave'];
        yield 'escritório não liberado pelo Master' => ['nao_liberado'];
        yield 'conector não ligado' => ['nao_ligado'];
        yield 'aceite em versão antiga' => ['aceite_antigo'];
    }

    #[DataProvider('semLiberacao')]
    #[TestDox('12.24 no authorize ($_dataName): a tela mostra o escritório desabilitado e o POST volta ao app com access_denied')]
    public function testSemLiberacaoRecusa(string $caso): void
    {
        $this->clienteValido();
        $tenant = $this->escritorio();
        $pessoa = $this->pessoa();
        $this->vinculo($pessoa, $tenant);
        match ($caso) {
            'chave' => [$this->liberarConector($tenant), $this->desligarChaveGeral()],
            'nao_liberado' => $this->liberarConector($tenant, liberado: false),
            'nao_ligado' => $this->liberarConector($tenant, ligado: false),
            'aceite_antigo' => $this->aceiteEmVersaoAntiga($this->liberarConector($tenant)),
        };
        $this->logarComTenant($this->client, $pessoa, $tenant);

        $tela = $this->abrir();
        self::assertCount(1, $tela->filter('input[value="' . $tenant->getId() . '"][disabled]'));
        self::assertStringContainsString(AcessoDoConectorRecusado::CONECTOR_NAO_LIBERADO, $tela->filter('main')->text());
        $this->enviar($this->campos($tela, (int) $tenant->getId()));

        $this->exigirRecusaNoApp();
        self::assertSame(0, $this->concessoesDe($pessoa));
    }
}
