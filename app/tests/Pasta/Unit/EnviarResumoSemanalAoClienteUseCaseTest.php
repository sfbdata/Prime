<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\EnvioAutomaticoDoCliente;
use App\Cliente\Enum\ChaveDoEnvioAutomatico;
use App\Cliente\Repository\ClienteEmailRepository;
use App\Cliente\Repository\EnvioAutomaticoDoClienteRepository;
use App\Djen\Entity\PublicacaoDjen;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\EnvioAoCliente;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaProcesso;
use App\Pasta\Repository\EnvioAoClienteRepository;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\ControladoriaDoEscritorioInterface;
use App\Pasta\Service\EmailsDoClienteDaPasta;
use App\Pasta\Service\EnvioAoCliente\EnvioDeEmailAoClienteInterface;
use App\Pasta\Service\EnvioAoCliente\MensagemAoCliente;
use App\Pasta\Service\EnvioAoCliente\ResultadoDoEnvioAoCliente;
use App\Pasta\Service\EnvioAutomaticoAoCliente;
use App\Pasta\Service\GruposDoEnvioAutomatico;
use App\Pasta\UseCase\EnviarResumoSemanalAoClienteUseCase;
use App\Processo\Entity\Processo;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use App\Tenant\Service\RegraDosPrimarios;
use App\Tests\Inteligencia\Support\DefineId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * O resumo semanal ao cliente (spec push-envio-automatico §3 e §4): a semana anterior de segunda a
 * domingo, contada do relógio; só com a chave ligada e movimentação na semana; uma vez por semana por
 * pasta; a simulação conta e não envia nem grava.
 */
#[CoversClass(EnviarResumoSemanalAoClienteUseCase::class)]
final class EnviarResumoSemanalAoClienteUseCaseTest extends TestCase
{
    private Tenant $tenant;
    private Pasta $pasta;
    private ClientePF $cliente;
    private Processo $processo;
    /** @var list<PublicacaoDjen> */
    private array $publicacoes = [];
    private ?EnvioAutomaticoDoCliente $chaves = null;
    private bool $jaSaiu = false;
    /** @var list<MensagemAoCliente> */
    private array $mensagens = [];
    /** @var list<EnvioAoCliente> */
    private array $gravados = [];
    /** @var array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable} */
    private array $periodoPedido = [null, null];
    private ?string $descricaoConsultada = null;

    protected function setUp(): void
    {
        $this->tenant = (new Tenant())->setName('Silva Advogados');
        DefineId::em($this->tenant, 1);
        $this->cliente = new ClientePF();
        $this->cliente->setNomeCompleto('Maria da Silva');
        $this->cliente->setTenant($this->tenant);
        $this->cliente->setEmail('maria@cliente.com.br');
        DefineId::em($this->cliente, 50);
        $this->pasta = new Pasta();
        $this->pasta->setTenant($this->tenant);
        $this->pasta->setNup('2026/0042');
        $this->pasta->setResponsavel((new User())->setEmail('resp@escritorio.com.br')->setIsActive(true));
        $this->pasta->addCliente($this->cliente);
        DefineId::em($this->pasta, 30);
        $this->processo = new Processo();
        $this->processo->setTenant($this->tenant);
        $this->processo->setNumeroProcesso('07011345720258070007');
        DefineId::em($this->processo, 40);
        $publicacao = new PublicacaoDjen();
        DefineId::em($publicacao, 701);
        $publicacao->setTipoComunicacao('Intimação');
        $publicacao->setDataDisponibilizacao(new \DateTimeImmutable('2026-10-14'));
        $publicacao->setTexto('Fica a parte intimada.');
        $publicacao->setProcesso($this->processo);
        $this->publicacoes = [$publicacao];
    }

    private function ligarResumo(): void
    {
        $this->chaves = new EnvioAutomaticoDoCliente($this->tenant, $this->cliente, null, new \DateTimeImmutable());
        $this->chaves->definir(ChaveDoEnvioAutomatico::ResumoSemanal, true, new User(), new \DateTimeImmutable());
    }

    private function useCase(string $agora = '2026-10-19 08:00:00'): EnviarResumoSemanalAoClienteUseCase
    {
        $publicacoes = $this->createStub(PublicacaoDjenRepository::class);
        $publicacoes->method('listarVinculadasDoPeriodoDoTenant')->willReturnCallback(function (Tenant $t, \DateTimeImmutable $inicio, \DateTimeImmutable $fim): array {
            $this->periodoPedido = [$inicio, $fim];

            return $this->publicacoes;
        });
        $pastas = $this->createStub(PastaRepository::class);
        $pastas->method('listarVinculosDePastasVivasDosProcessos')->willReturn([new PastaProcesso($this->pasta, $this->processo)]);
        $emails = $this->createStub(ClienteEmailRepository::class);
        $emails->method('listarDoCliente')->willReturn([]);
        $automaticos = $this->createStub(EnvioAutomaticoDoClienteRepository::class);
        $automaticos->method('porClientesDoTenant')->willReturnCallback(fn (): array => $this->chaves !== null ? [50 => $this->chaves] : []);
        $envios = $this->createStub(EnvioAoClienteRepository::class);
        $envios->method('existeEnvioAutomaticoDaPasta')->willReturnCallback(function (Tenant $t, Pasta $p, string $descricao): bool {
            $this->descricaoConsultada = $descricao;

            return $this->jaSaiu;
        });
        $envios->method('salvar')->willReturnCallback(function (EnvioAoCliente $e): void {
            $this->gravados[] = $e;
        });
        $porta = $this->createStub(EnvioDeEmailAoClienteInterface::class);
        $porta->method('enviar')->willReturnCallback(function (MensagemAoCliente $m): ResultadoDoEnvioAoCliente {
            $this->mensagens[] = $m;

            return new ResultadoDoEnvioAoCliente(null, ResultadoDoEnvioAoCliente::ENVIADO);
        });
        $daPasta = new EmailsDoClienteDaPasta($emails);
        $vinculos = $this->createStub(UserTenantRepository::class);
        $vinculos->method('findAtivosComPerfilPorTenant')->willReturn([]);
        $envio = new EnvioAutomaticoAoCliente(
            $daPasta,
            $this->createStub(DescadastroDeEmailInterface::class),
            $this->createStub(ControladoriaDoEscritorioInterface::class),
            $porta,
            $envios,
            $vinculos,
            new RegraDosPrimarios($vinculos),
            $this->createStub(NotificacaoService::class),
            $this->createStub(UrlGeneratorInterface::class),
            new MockClock($agora),
            new NullLogger(),
        );

        return new EnviarResumoSemanalAoClienteUseCase($publicacoes, new GruposDoEnvioAutomatico($pastas, $daPasta, $automaticos), $envios, $envio, new MockClock($agora));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function semanas(): iterable
    {
        yield 'segunda de manhã' => ['2026-10-19 08:00', '2026-10-12', '2026-10-18'];
        yield 'quarta' => ['2026-10-21 15:00', '2026-10-12', '2026-10-18'];
        yield 'domingo à noite' => ['2026-10-25 23:59', '2026-10-12', '2026-10-18'];
        yield 'virada de mês' => ['2026-11-02 08:00', '2026-10-26', '2026-11-01'];
    }

    #[DataProvider('semanas')]
    #[TestDox('Semana anterior (segunda a domingo) a partir de $_dataName')]
    public function testSemanaAnterior(string $agora, string $inicio, string $fim): void
    {
        [$de, $ate] = EnviarResumoSemanalAoClienteUseCase::semanaAnterior(new \DateTimeImmutable($agora));

        self::assertSame($inicio, $de->format('Y-m-d'));
        self::assertSame($fim, $ate->format('Y-m-d'));
    }

    #[TestDox('Chave ligada e movimentação na semana: um e-mail com o resumo, consulta da semana anterior e uma linha "resumo semanal de 12/10 a 18/10" no histórico')]
    public function testEnviaOResumo(): void
    {
        $this->ligarResumo();

        $resultado = $this->useCase()->executar($this->tenant);

        self::assertSame('2026-10-12', $this->periodoPedido[0]?->format('Y-m-d'));
        self::assertSame('2026-10-18', $this->periodoPedido[1]?->format('Y-m-d'));
        self::assertSame(1, $resultado->pastas);
        self::assertSame(1, $resultado->emails);
        self::assertSame('12/10 a 18/10', $resultado->semana());
        self::assertCount(1, $this->mensagens);
        self::assertSame('Resumo semanal do seu processo 0701134-57.2025.8.07.0007', $this->mensagens[0]->assunto);
        self::assertStringContainsString('na semana de 12/10 a 18/10', $this->mensagens[0]->corpoHtml);
        self::assertStringContainsString('Fica a parte intimada.', $this->mensagens[0]->corpoHtml);
        self::assertCount(1, $this->gravados);
        self::assertSame('resumo semanal de 12/10 a 18/10', $this->gravados[0]->getDescricao());
        self::assertNull($this->gravados[0]->getReferencia());
    }

    #[TestDox('Chave desligada (padrão), semana sem movimentação ou resumo da semana já enviado: nada sai')]
    public function testNaoEnvia(): void
    {
        $this->useCase()->executar($this->tenant);
        self::assertSame([], $this->mensagens, 'padrão desligado');

        $this->ligarResumo();
        $this->publicacoes = [];
        self::assertSame(0, $this->useCase()->executar($this->tenant)->pastas, 'sem movimentação na semana');

        $this->setUp();
        $this->ligarResumo();
        $this->jaSaiu = true;
        self::assertSame(0, $this->useCase()->executar($this->tenant)->pastas, 'já enviado nesta semana');
        self::assertSame('resumo semanal de 12/10 a 18/10', $this->descricaoConsultada);
        self::assertSame([], $this->mensagens);
        self::assertSame([], $this->gravados);
    }

    #[TestDox('Simulação: conta a pasta e o e-mail, não envia nem grava')]
    public function testSimulacao(): void
    {
        $this->ligarResumo();

        $resultado = $this->useCase()->executar($this->tenant, true);

        self::assertSame(1, $resultado->pastas);
        self::assertSame(1, $resultado->emails);
        self::assertSame([], $this->mensagens);
        self::assertSame([], $this->gravados);
    }
}
