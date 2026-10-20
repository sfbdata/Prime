<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Comercial\Consentimento\ResultadoDaConfirmacao;
use App\Comercial\Entity\FatoDeConsentimento;
use App\Comercial\Envio\CarteiroInterface;
use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Envio\ResultadoDoEnvio;
use App\Comercial\Envio\TipoDeEnvio;
use App\Comercial\Repository\ConfirmacaoDeEnderecoRepository;
use App\Comercial\Repository\FatoDeConsentimentoRepository;
use App\Comercial\Service\ConfirmacaoDeEndereco;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * As regras do double opt-in (spec `comercial-e-emails.md` §3.3) com os repositórios, o descadastro e o Carteiro
 * dublados. O SQL se prova em {@see \App\Tests\Comercial\Functional\ConfirmacaoDeEnderecoIntegracaoTest}.
 */
#[CoversClass(ConfirmacaoDeEndereco::class)]
final class ConfirmacaoDeEnderecoTest extends TestCase
{
    private const LISTA = 'vagas_alerta';
    private const EMAIL = 'joana@gmail.com';

    private ConfirmacaoDeEnderecoRepository&MockObject $estados;
    private FatoDeConsentimentoRepository&MockObject $fatos;
    private DescadastroDeEmailInterface&MockObject $descadastro;
    private CarteiroInterface&MockObject $carteiro;
    private MockClock $relogio;
    /** @var list<MensagemDeEmail> */
    private array $enviadas = [];
    private AbstractLogger $logger;

    protected function setUp(): void
    {
        $this->estados = $this->createMock(ConfirmacaoDeEnderecoRepository::class);
        $this->estados->method('emTransacao')->willReturnCallback(static fn (\Closure $trabalho): mixed => $trabalho());
        $this->fatos = $this->createMock(FatoDeConsentimentoRepository::class);
        $this->descadastro = $this->createMock(DescadastroDeEmailInterface::class);
        $this->carteiro = $this->createMock(CarteiroInterface::class);
        $this->carteiro->method('enviar')->willReturnCallback(function (MensagemDeEmail $m): ResultadoDoEnvio {
            $this->enviadas[] = $m;

            return new ResultadoDoEnvio(1, ResultadoDoEnvio::ENFILEIRADO);
        });
        $this->relogio = new MockClock(new \DateTimeImmutable('2026-10-19 10:00:00'));
        $this->logger = new class () extends AbstractLogger {
            /** @var list<array{mensagem: string, contexto: array<mixed>}> */
            public array $registros = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->registros[] = ['mensagem' => (string) $message, 'contexto' => $context];
            }
        };
    }

    private function servico(): ConfirmacaoDeEndereco
    {
        $rotas = new RouteCollection();
        $rotas->add(ConfirmacaoDeEndereco::ROTA, new Route('/e/confirmar/{token}'));
        $urls = new UrlGenerator($rotas, new RequestContext('', 'GET', 'app.bluejus.com.br', 'https'));
        $twig = new Environment(new FilesystemLoader(\dirname(__DIR__, 3) . '/templates'), ['strict_variables' => true]);

        return new ConfirmacaoDeEndereco($this->estados, $this->fatos, $this->descadastro, $this->carteiro, $twig, $urls, $this->logger, $this->relogio);
    }

    private function pedir(string $email = self::EMAIL): void
    {
        $this->servico()->pedir(self::LISTA, $email, 'pagina-publica-vagas', 'v1', 'Quero receber os alertas de vagas.');
    }

    /** @return array{id: int, tenant_id: ?int, lista: string, email: string, expira_em: ?string, confirmado_em: ?string} */
    private static function estado(?string $confirmadoEm, ?string $expiraEm = '2026-10-26 10:00:00'): array
    {
        return ['id' => 7, 'tenant_id' => null, 'lista' => self::LISTA, 'email' => self::EMAIL, 'expira_em' => $expiraEm, 'confirmado_em' => $confirmadoEm];
    }

    #[TestDox('pedir sobre endereço confirmado (e não descadastrado) não grava, não troca o token e não manda e-mail')]
    public function testPedirSobreConfirmado(): void
    {
        $this->estados->method('buscar')->willReturn(self::estado('2026-01-01 10:00:00', null));
        $this->descadastro->method('estaDescadastrado')->willReturn(false);
        $this->fatos->expects(self::never())->method('registrar');
        $this->estados->expects(self::never())->method('gravarPedido');

        $this->pedir();

        self::assertSame([], $this->enviadas);
    }

    #[TestDox('pedir grava o fato "pedido" sem IP, o estado só com o hash do token (7 dias) e manda o e-mail transacional com o link')]
    public function testPedirGravaEEnvia(): void
    {
        $this->estados->method('buscar')->willReturn(null);
        $this->fatos->method('ultimoPedidoEm')->willReturn(null);
        $this->fatos->expects(self::once())->method('registrar')->with(
            null,
            FatoDeConsentimento::PEDIDO,
            self::LISTA,
            self::EMAIL,
            ['origem' => 'pagina-publica-vagas', 'versao' => 'v1', 'texto' => 'Quero receber os alertas de vagas.'],
            null,
            null,
            $this->relogio->now(),
        );
        $hash = null;
        $this->estados->expects(self::once())->method('gravarPedido')->willReturnCallback(
            function (?int $tenant, string $lista, string $email, string $tokenHash, \DateTimeImmutable $pedido, \DateTimeImmutable $expira) use (&$hash): void {
                $hash = $tokenHash;
                self::assertSame('2026-10-26 10:00:00', $expira->format('Y-m-d H:i:s'));
            },
        );

        $this->pedir('  Joana@Gmail.COM ');

        self::assertCount(1, $this->enviadas);
        $mensagem = $this->enviadas[0];
        self::assertSame(self::EMAIL, $mensagem->para);
        self::assertSame(TipoDeEnvio::Transacional, $mensagem->tipo);
        self::assertNull($mensagem->lista);
        self::assertSame('consentimento:vagas_alerta', $mensagem->origem);
        self::assertSame(1, preg_match('#https://app\.bluejus\.com\.br/e/confirmar/([0-9a-f]{64})#', $mensagem->corpoHtml, $achado));
        self::assertSame(hash('sha256', $achado[1]), $hash, 'só o hash do token do link vai para o banco');
        self::assertStringNotContainsString($achado[1], (string) $hash);
    }

    #[TestDox('O e-mail diz o nome da lista e o rótulo da origem, nunca os identificadores crus, e fala do prazo de 7 dias')]
    public function testCorpoDoEmail(): void
    {
        $this->estados->method('buscar')->willReturn(null);

        $this->pedir();

        $corpo = $this->enviadas[0]->corpoHtml;
        self::assertStringContainsString('Confirme que quer receber os alertas de vagas do BlueJus', $corpo);
        self::assertStringContainsString('Confirmar meu e-mail', $corpo);
        self::assertStringContainsString('O link vale por 7 dias.', $corpo);
        self::assertSame('Este endereço foi informado na página de vagas do BlueJus. Se não foi você, ignore este e-mail.', $this->enviadas[0]->motivoDoEnvio, 'a frase vai no rodapé, no lugar da transacional');
        self::assertStringNotContainsString('pagina-publica-vagas', $corpo);
        self::assertStringNotContainsString('vagas_alerta', $corpo);
        self::assertStringNotContainsString('—', $corpo);
        self::assertStringNotContainsString('–', $corpo);
    }

    /** @return iterable<string, array{bool, string, bool}> */
    public static function limitesPorEndereco(): iterable
    {
        yield '24 h: pedido de 23 h atrás bloqueia' => [false, '-23 hours', false];
        yield '24 h: pedido de 25 h atrás libera' => [false, '-25 hours', true];
        yield '30 dias (descadastrado): pedido de 29 dias atrás bloqueia' => [true, '-29 days', false];
        yield '30 dias (descadastrado): pedido de 31 dias atrás libera' => [true, '-31 days', true];
    }

    #[DataProvider('limitesPorEndereco')]
    #[TestDox('Limite por endereço e lista: $_dataName')]
    public function testLimitePorEndereco(bool $descadastrado, string $ultimoPedido, bool $envia): void
    {
        $this->estados->method('buscar')->willReturn($descadastrado ? self::estado('2026-01-01 10:00:00', null) : null);
        $this->descadastro->method('estaDescadastrado')->willReturn($descadastrado);
        $this->fatos->method('ultimoPedidoEm')->willReturn($this->relogio->now()->modify($ultimoPedido));
        $this->fatos->expects($envia ? self::once() : self::never())->method('registrar');

        $this->pedir();

        self::assertCount($envia ? 1 : 0, $this->enviadas);
    }

    #[TestDox('Teto global: com 200 pedidos na última hora não envia, sem exceção, e o log não leva o endereço')]
    public function testTetoGlobal(): void
    {
        $this->estados->method('buscar')->willReturn(null);
        $this->fatos->method('ultimoPedidoEm')->willReturn(null);
        $this->fatos->method('contarPedidosDesde')->with($this->relogio->now()->modify('-1 hour'))->willReturn(ConfirmacaoDeEndereco::TETO_POR_HORA);
        $this->estados->expects(self::never())->method('gravarPedido');

        $this->pedir();

        self::assertSame([], $this->enviadas);
        self::assertCount(1, $this->logger->registros);
        self::assertSame('global', $this->logger->registros[0]['contexto']['limite']);
        self::assertStringNotContainsString('joana', json_encode($this->logger->registros, JSON_THROW_ON_ERROR));
    }

    #[TestDox('Teto global: com 199 pedidos na última hora ainda envia')]
    public function testAbaixoDoTeto(): void
    {
        $this->estados->method('buscar')->willReturn(null);
        $this->fatos->method('contarPedidosDesde')->willReturn(ConfirmacaoDeEndereco::TETO_POR_HORA - 1);

        $this->pedir();

        self::assertCount(1, $this->enviadas);
    }

    #[TestDox('pedir recusa lista fora do padrão, "todos" e e-mail inválido')]
    public function testPedidoInvalido(): void
    {
        foreach ([['Vagas Alerta', self::EMAIL], ['todos', self::EMAIL], [self::LISTA, 'nao-e-email']] as [$lista, $email]) {
            try {
                $this->servico()->pedir($lista, $email, 'pagina-publica-vagas', 'v1', 'texto');
                self::fail('deveria recusar');
            } catch (\InvalidArgumentException) {
                self::assertSame([], $this->enviadas);
            }
        }
    }

    #[TestDox('confirmar: grava o fato "confirmado" com IP, agente e o consentimento do último pedido, e reativa a lista')]
    public function testConfirmar(): void
    {
        $token = str_repeat('a', 64);
        $this->estados->method('buscarPorTokenHash')->with(hash('sha256', $token))->willReturn(self::estado(null));
        $this->estados->expects(self::once())->method('marcarConfirmado')->with(7, hash('sha256', $token), $this->relogio->now())->willReturn(true);
        $consentimento = ['origem' => 'pagina-publica-vagas', 'versao' => 'v1', 'texto' => 'Quero receber.'];
        $this->fatos->method('ultimoPedido')->willReturn($consentimento);
        $this->fatos->expects(self::once())->method('registrar')
            ->with(null, FatoDeConsentimento::CONFIRMADO, self::LISTA, self::EMAIL, $consentimento, '203.0.113.9', 'Navegador/1.0', $this->relogio->now());
        $this->descadastro->expects(self::once())->method('reativar')->with(null, self::EMAIL, self::LISTA, 'nova_confirmacao');

        $resultado = $this->servico()->confirmar($token, '203.0.113.9', 'Navegador/1.0');

        self::assertSame(ResultadoDaConfirmacao::Confirmado, $resultado);
    }

    /** @return iterable<string, array{?array<string, mixed>, bool}> */
    public static function linksQueNaoValem(): iterable
    {
        yield 'token desconhecido, já usado ou trocado' => [null, false];
        yield 'vencido' => [self::estado(null, '2026-10-19 09:59:59'), false];
        yield 'sem prazo (já confirmado)' => [self::estado('2026-10-01 10:00:00', null), false];
        yield 'outra confirmação venceu a corrida' => [self::estado(null), true];
    }

    /** @param array<string, mixed>|null $estado */
    #[DataProvider('linksQueNaoValem')]
    #[TestDox('confirmar devolve VencidoOuUsado e não grava fato nem reativa: $_dataName')]
    public function testLinkQueNaoVale(?array $estado, bool $chegaAoUpdate): void
    {
        $this->estados->method('buscarPorTokenHash')->willReturn($estado);
        $this->estados->expects($chegaAoUpdate ? self::once() : self::never())->method('marcarConfirmado')->willReturn(false);
        $this->fatos->expects(self::never())->method('registrar');
        $this->descadastro->expects(self::never())->method('reativar');

        $resultado = $this->servico()->confirmar(str_repeat('b', 64), '203.0.113.9', null);

        self::assertSame(ResultadoDaConfirmacao::VencidoOuUsado, $resultado);
    }

    /** @return iterable<string, array{?string, bool, bool}> */
    public static function situacoes(): iterable
    {
        yield 'confirmado e não descadastrado' => ['2026-10-01 10:00:00', false, true];
        yield 'confirmado mas descadastrado' => ['2026-10-01 10:00:00', true, false];
        yield 'pendente' => [null, false, false];
    }

    #[DataProvider('situacoes')]
    #[TestDox('estaConfirmado: $_dataName')]
    public function testEstaConfirmado(?string $confirmadoEm, bool $descadastrado, bool $esperado): void
    {
        $this->estados->method('buscar')->with(null, self::LISTA, self::EMAIL)->willReturn(self::estado($confirmadoEm));
        $this->descadastro->method('estaDescadastrado')->willReturn($descadastrado);

        self::assertSame($esperado, $this->servico()->estaConfirmado(self::LISTA, ' JOANA@gmail.com'));
    }

    #[TestDox('estaConfirmado sem estado nenhum é falso')]
    public function testEstaConfirmadoSemEstado(): void
    {
        $this->estados->method('buscar')->willReturn(null);

        self::assertFalse($this->servico()->estaConfirmado(self::LISTA, self::EMAIL));
    }
}
