<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit;

use App\Contribua\Banco\BancoBoleto;
use App\Contribua\Banco\BancoPix;
use App\Contribua\Banco\BancosPix;
use App\Contribua\Banco\BoletoEmitido;
use App\Contribua\Banco\CobrancaPix;
use App\Contribua\Banco\FalhaNoBanco;
use App\Contribua\Banco\PedidoDeBoleto;
use App\Contribua\Banco\PedidoDeCobrancaPix;
use App\Contribua\Banco\ResultadoDoTeste;
use App\Contribua\Banco\SituacaoDaCobranca;
use App\Contribua\DTO\Solicitante;
use App\Contribua\Entity\CobrancaPixDaConta;
use App\Contribua\Entity\IntegracaoBancaria;
use App\Contribua\Exception\BoletoIndisponivel;
use App\Contribua\Exception\BoletoNaoGerado;
use App\Contribua\Repository\CobrancaPixDaContaRepository;
use App\Contribua\Repository\IntegracaoBancariaRepository;
use App\Contribua\UseCase\GerarBoletoDeApoioUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(GerarBoletoDeApoioUseCase::class)]
final class GerarBoletoDeApoioUseCaseTest extends TestCase
{
    private const CNPJ = '11222333000181';

    private IntegracaoBancaria $integracao;
    private BancoFalsoDeBoleto $cora;
    private CobrancaPixDaContaRepository&MockObject $cobrancas;
    private ArrayAdapter $cache;
    /** @var list<CobrancaPixDaConta> */
    private array $salvas = [];
    /** @var list<array{string, array<string, mixed>}> */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->integracao = self::integracao('cora', 'producao', true);
        $this->cora = new BancoFalsoDeBoleto();
        $this->cache = new ArrayAdapter();
        $this->cobrancas = $this->createMock(CobrancaPixDaContaRepository::class);
        $this->cobrancas->method('comTravaDeBoleto')->willReturnCallback(static fn (Tenant $t, callable $acao): mixed => $acao());
        $this->cobrancas->method('salvar')->willReturnCallback(function (CobrancaPixDaConta $c): void {
            $this->salvas[] = $c;
        });
    }

    #[TestDox('Gera o boleto na Cora: nome do escritório, e-mail de quem pede, vencimento em 3 dias; a linha nasce como boleto já registrado')]
    public function testGera(): void
    {
        $boleto = $this->sut()->executar($this->quem(), 2000, '11.222.333/0001-81');

        self::assertSame('4039 linha', $boleto->linhaDigitavel);
        self::assertSame('https://cora.test/b.pdf', $boleto->url);
        self::assertSame('R$ 20,00', $boleto->valor);
        self::assertSame((new \DateTimeImmutable('today +3 days'))->format('d/m/Y'), $boleto->vencimento);
        $pedido = $this->cora->pedidos[0];
        self::assertSame([self::CNPJ, 'quem@escritorio.test', 'Escritório Teste'], [$pedido->documento, $pedido->email, $pedido->nomeDoPagador]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $pedido->chaveDeIdempotencia);
        self::assertMatchesRegularExpression('/^BJ000000-B[0-9a-f]{10}$/', $pedido->codigo);
        self::assertCount(1, $this->salvas);
        self::assertSame([CobrancaPixDaConta::FORMA_BOLETO, 'inv_1', $pedido->codigo, $pedido->chaveDeIdempotencia], [$this->salvas[0]->getForma(), $this->salvas[0]->getIdNoBanco(), $this->salvas[0]->getCodigo(), $this->salvas[0]->getChaveDeIdempotencia()]);
    }

    #[TestDox('Idempotência: o boleto aberto de hoje no mesmo valor volta sem chamar o banco')]
    public function testDevolveODoDia(): void
    {
        $existente = CobrancaPixDaConta::boletoEmitido(new Tenant(), 'cora', 'producao', 2000, new \DateTimeImmutable('+3 days'), 'k', 'BJ1-B1', 'inv_9', 'linha 9', 'https://cora.test/9.pdf', null);
        $this->cobrancas->method('boletoAbertoDoDia')->willReturn($existente);

        $boleto = $this->sut()->executar($this->quem(), 2000, self::CNPJ);

        self::assertSame('linha 9', $boleto->linhaDigitavel);
        self::assertSame([], $this->cora->pedidos);
        self::assertSame([], $this->salvas);
    }

    #[TestDox('Falha da Cora: mensagem honesta, nenhuma linha gravada, documento e e-mail fora do log; passada a pausa, repetir usa a mesma chave e o mesmo código')]
    public function testFalhaERepeticao(): void
    {
        $this->cora->falha = new FalhaNoBanco('A Cora respondeu 400: document 11222333000181 invalid, customer.email quem@escritorio.test invalid.', 0, null, false);
        try {
            $this->sut()->executar($this->quem(), 2000, self::CNPJ);
            self::fail('deveria falhar');
        } catch (BoletoNaoGerado $e) {
            self::assertSame('Não foi possível gerar o boleto agora. Tente de novo em instantes.', $e->getMessage());
        }
        self::assertSame([], $this->salvas);
        self::assertStringNotContainsString(self::CNPJ, (string) json_encode($this->logs));
        self::assertStringNotContainsString('quem@escritorio.test', (string) json_encode($this->logs));
        self::assertStringContainsString('[e-mail]', (string) $this->logs[0][1]['motivo']);
        self::assertStringContainsString('[número]', (string) $this->logs[0][1]['motivo']);

        $this->cache->clear();
        $this->cora->falha = null;
        $this->sut()->executar($this->quem(), 2000, self::CNPJ);
        self::assertSame($this->cora->pedidos[0]->chaveDeIdempotencia, $this->cora->pedidos[1]->chaveDeIdempotencia);
        self::assertSame($this->cora->pedidos[0]->codigo, $this->cora->pedidos[1]->codigo);
    }

    #[TestDox('Falha da Cora liga a pausa do escritório: durante ela o pedido volta com a mesma mensagem, sem chamar a Cora nem abrir a trava')]
    public function testPausa(): void
    {
        $this->cora->falha = new FalhaNoBanco('A Cora respondeu 500.');
        try {
            $this->sut()->executar($this->quem(), 2000, self::CNPJ);
            self::fail('deveria falhar');
        } catch (BoletoNaoGerado) {
        }
        $this->cora->falha = null;
        $sut = $this->sutSemTrava();

        try {
            $sut->executar($this->quem(), 5000, self::CNPJ);
            self::fail('em pausa');
        } catch (BoletoNaoGerado $e) {
            self::assertSame(BoletoNaoGerado::MENSAGEM, $e->getMessage());
        }
        self::assertCount(1, $this->cora->pedidos, 'em pausa, nem chama a Cora');
    }

    #[TestDox('Chave muda com o valor, o documento e o n-ésimo boleto do mesmo valor no dia; boleto de outro valor não muda a chave')]
    public function testChaveDerivada(): void
    {
        $doMesmoValor = 0;
        $total = 0;
        $this->cobrancas->method('criadasDesde')->willReturnCallback(static function (Tenant $t, \DateTimeImmutable $d, string $forma, ?int $valor = null) use (&$doMesmoValor, &$total): int {
            return null === $valor ? $total : $doMesmoValor;
        });
        $this->sut()->executar($this->quem(), 2000, self::CNPJ);
        $this->sut()->executar($this->quem(), 5000, self::CNPJ);
        $this->sut()->executar($this->quem(), 2000, '52998224725');
        $total = 3;
        $this->sut()->executar($this->quem(), 2000, self::CNPJ);
        $doMesmoValor = 1;
        $this->sut()->executar($this->quem(), 2000, self::CNPJ);

        $chaves = array_map(static fn (PedidoDeBoleto $p): string => $p->chaveDeIdempotencia, $this->cora->pedidos);
        self::assertCount(3, array_unique(array_slice($chaves, 0, 3)));
        self::assertSame($chaves[0], $chaves[3], 'boletos de outros valores no dia não mudam a chave');
        self::assertNotSame($chaves[0], $chaves[4], 'o segundo boleto do mesmo valor tem outra chave');
    }

    #[TestDox('A fatura que a Cora devolveu pela chave e que já é nossa não vira outra linha')]
    public function testFaturaJaNossa(): void
    {
        $nossa = CobrancaPixDaConta::boletoEmitido(new Tenant(), 'cora', 'producao', 2000, new \DateTimeImmutable('+3 days'), 'k', 'BJ1-B1', 'inv_1', 'linha 1', 'https://cora.test/1.pdf', null);
        $this->cobrancas->method('doBanco')->willReturn($nossa);

        self::assertSame('linha 1', $this->sut()->executar($this->quem(), 2000, self::CNPJ)->linhaDigitavel);
        self::assertSame([], $this->salvas);
    }

    #[TestDox('Chave Boleto desligada, integração não conectada, outro banco ou sandbox fora do Master: indisponível, sem chamar banco')]
    public function testIndisponivel(): void
    {
        $casos = [
            'chave desligada' => [self::integracao('cora', 'producao', false), false],
            'não conectada' => [self::integracao('cora', 'producao', true, false), false],
            'outro banco' => [self::integracao('inter', 'producao', true), false],
            'sandbox sem Master' => [self::integracao('cora', 'sandbox', true), false],
        ];
        foreach ($casos as $nome => [$integracao, $master]) {
            $this->integracao = $integracao;
            self::assertFalse($this->sut()->disponivelPara($this->quem($master)), $nome);
            try {
                $this->sut()->executar($this->quem($master), 2000, self::CNPJ);
                self::fail($nome);
            } catch (BoletoIndisponivel) {
            }
        }
        self::assertSame([], $this->cora->pedidos);

        $this->integracao = self::integracao('cora', 'sandbox', true);
        self::assertTrue($this->sut()->disponivelPara($this->quem(true)), 'no sandbox, o Master testa');
    }

    #[TestDox('Recusas: abaixo de R$ 5,00, documento inválido e limite do dia')]
    public function testRecusas(): void
    {
        foreach ([[499, self::CNPJ, 'O boleto tem valor mínimo de R$ 5,00.'], [2000, '11222333000180', 'Informe um CPF ou CNPJ válido do pagador.'], [2000, '', 'Informe um CPF ou CNPJ válido do pagador.']] as [$valor, $doc, $msg]) {
            try {
                $this->sut()->executar($this->quem(), $valor, $doc);
                self::fail($msg);
            } catch (\DomainException $e) {
                self::assertSame($msg, $e->getMessage());
            }
        }
        $this->cobrancas->method('criadasDesde')->willReturn(GerarBoletoDeApoioUseCase::MAXIMO_POR_DIA);
        $this->expectExceptionMessage('Limite de boletos de hoje atingido');
        $this->sut()->executar($this->quem(), 2000, self::CNPJ);
    }

    private function sut(): GerarBoletoDeApoioUseCase
    {
        $repo = $this->createStub(IntegracaoBancariaRepository::class);
        $repo->method('atual')->willReturn($this->integracao);
        $logs = &$this->logs;
        $logger = new class ($logs) extends AbstractLogger {
            /** @param list<array{string, array<string, mixed>}> $logs */
            public function __construct(private array &$logs)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logs[] = [(string) $message, $context];
            }
        };

        return new GerarBoletoDeApoioUseCase($repo, new BancosPix([$this->cora, new BancoSoPix()]), $this->cobrancas, $logger, $this->cache, 'segredo-de-teste');
    }

    /** O mesmo caso de uso com um repositório que falha se a trava (a transação) for aberta. */
    private function sutSemTrava(): GerarBoletoDeApoioUseCase
    {
        $this->cobrancas = $this->createMock(CobrancaPixDaContaRepository::class);
        $this->cobrancas->expects(self::never())->method('comTravaDeBoleto');

        return $this->sut();
    }

    private function quem(bool $master = false): Solicitante
    {
        $usuario = (new User())->setEmail('quem@escritorio.test');
        $escritorio = (new Tenant())->setName('Escritório Teste');

        return new Solicitante($usuario, $escritorio, $master, true);
    }

    private static function integracao(string $banco, string $ambiente, bool $boleto, bool $conectada = true): IntegracaoBancaria
    {
        $i = new IntegracaoBancaria();
        $i->guardarCertificado('cert', 'chave', ['cert.pem'], new User());
        $i->escolherBanco($banco);
        $i->configurar($ambiente, 'cliente-1', ['bol' => $boleto], new User());
        if ($conectada) {
            $i->registrarTeste(true, 'ok', new \DateTimeImmutable());
        }

        return $i;
    }
}

/** A Cora do teste: emite boleto e registra cada pedido. */
final class BancoFalsoDeBoleto implements BancoPix, BancoBoleto
{
    /** @var list<PedidoDeBoleto> */
    public array $pedidos = [];
    public ?FalhaNoBanco $falha = null;

    public function codigo(): string
    {
        return 'cora';
    }

    public function testarConexao(): ResultadoDoTeste
    {
        return new ResultadoDoTeste(true, 'ok');
    }

    public function criarCobranca(PedidoDeCobrancaPix $pedido): CobrancaPix
    {
        throw new \LogicException('não usado');
    }

    public function consultar(string $idNoBanco): SituacaoDaCobranca
    {
        throw new \LogicException('não usado');
    }

    public function emitirBoleto(PedidoDeBoleto $pedido): BoletoEmitido
    {
        $this->pedidos[] = $pedido;
        if (null !== $this->falha) {
            throw $this->falha;
        }

        return new BoletoEmitido('inv_' . count($this->pedidos), '4039 linha', 'https://cora.test/b.pdf', null);
    }
}

/** Um banco só de PIX (Inter, BB, Outro): não emite boleto. */
final class BancoSoPix implements BancoPix
{
    public function codigo(): string
    {
        return 'inter';
    }

    public function testarConexao(): ResultadoDoTeste
    {
        return new ResultadoDoTeste(true, 'ok');
    }

    public function criarCobranca(PedidoDeCobrancaPix $pedido): CobrancaPix
    {
        throw new \LogicException('não usado');
    }

    public function consultar(string $idNoBanco): SituacaoDaCobranca
    {
        throw new \LogicException('não usado');
    }
}
