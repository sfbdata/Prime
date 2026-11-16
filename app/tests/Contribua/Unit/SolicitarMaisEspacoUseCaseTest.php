<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit;

use App\Contribua\DTO\PedidoDeEspacoDoEscritorioView;
use App\Contribua\Entity\EspacoDoEscritorio;
use App\Contribua\Entity\PedidoDeEspaco;
use App\Contribua\Enum\OrigemDaLiberacao;
use App\Contribua\Event\EspacoExtraFoiSolicitado;
use App\Contribua\Event\EventosDoEspacoAposConfirmar;
use App\Contribua\Repository\PedidoDeEspacoRepository;
use App\Contribua\Service\AvisoAoMaster;
use App\Contribua\UseCase\SolicitarMaisEspacoUseCase;
use App\Contribua\UseCase\VerPedidoDeEspacoDoEscritorioUseCase;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Tests\Contribua\Doubles\CriaSituacaoDoEspaco;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[CoversClass(SolicitarMaisEspacoUseCase::class)]
#[CoversClass(VerPedidoDeEspacoDoEscritorioUseCase::class)]
#[CoversClass(PedidoDeEspaco::class)]
final class SolicitarMaisEspacoUseCaseTest extends TestCase
{
    use CriaSituacaoDoEspaco;

    /** @var list<object> */
    private array $despachados = [];

    #[TestDox('O primário pede: grava uso e limite do momento, responde "Pedido enviado" e despacha o evento depois do flush')]
    public function testPrimarioPede(): void
    {
        $gravado = null;
        $pedidos = $this->createMock(PedidoDeEspacoRepository::class);
        $pedidos->method('pendenteDoEscritorio')->willReturn(null);
        $pedidos->expects(self::once())->method('salvar')->willReturnCallback(static function (PedidoDeEspaco $p) use (&$gravado): void {
            (new \ReflectionProperty(PedidoDeEspaco::class, 'id'))->setValue($p, 31);
            $gravado = $p;
        });
        $useCase = $this->useCase($pedidos, $this->gb(4), null, $eventos);

        self::assertSame('Pedido enviado', $useCase->executar($this->quemDeTeste(primario: true), 50, '  Vamos digitalizar tudo  '));
        $this->confirmar($eventos);

        self::assertSame([50, 'Vamos digitalizar tudo', $this->gb(4), $this->gb(10), true], [$gravado->getGbDesejado(), $gravado->getMotivo(), $gravado->getUsoBytes(), $gravado->getLimiteBytes(), $gravado->estaPendente()]);
        self::assertSame(7, (int) $gravado->getTenant()->getId());
        self::assertCount(1, $this->despachados);
        self::assertInstanceOf(EspacoExtraFoiSolicitado::class, $this->despachados[0]);
        self::assertSame([31, 7, 50, 55], [$this->despachados[0]->pedidoId, $this->despachados[0]->tenantId, $this->despachados[0]->gbDesejado, $this->despachados[0]->pedidoPorUserId]);
    }

    #[TestDox('O Master também pode pedir; quem não é primário nem Master é recusado sem gravar')]
    public function testQuemPode(): void
    {
        $pedidos = $this->createMock(PedidoDeEspacoRepository::class);
        $pedidos->method('pendenteDoEscritorio')->willReturn(null);
        $pedidos->expects(self::once())->method('salvar');
        $useCase = $this->useCase($pedidos, $this->gb(4), null, $eventos);

        self::assertSame('Pedido enviado', $useCase->executar($this->quemDeTeste(primario: false, master: true), 20, null));

        $this->expectExceptionMessage('Quem pede mais espaço é o usuário primário do escritório.');
        $useCase->executar($this->quemDeTeste(primario: false), 20, null);
    }

    #[TestDox('Um pendente por escritório: o segundo pedido é recusado')]
    public function testUmPendente(): void
    {
        $pedidos = $this->createMock(PedidoDeEspacoRepository::class);
        $pedidos->method('pendenteDoEscritorio')->willReturn($this->pedido());
        $pedidos->expects(self::never())->method('salvar');

        $this->expectExceptionMessage('Já existe um pedido de espaço em análise para o seu escritório.');
        $this->useCase($pedidos, $this->gb(4), null, $eventos)->executar($this->quemDeTeste(primario: true), 20, null);
    }

    #[TestDox('Só 20, 50 ou 100 GB; motivo acima de 300 caracteres é recusado')]
    #[DataProvider('pedidosInvalidos')]
    public function testValidacoes(int $gb, ?string $motivo, string $mensagem): void
    {
        $pedidos = $this->createMock(PedidoDeEspacoRepository::class);
        $pedidos->method('pendenteDoEscritorio')->willReturn(null);
        $pedidos->expects(self::never())->method('salvar');

        $this->expectExceptionMessage($mensagem);
        $this->useCase($pedidos, $this->gb(4), null, $eventos)->executar($this->quemDeTeste(primario: true), $gb, $motivo);
    }

    /** @return iterable<string, array{int, ?string, string}> */
    public static function pedidosInvalidos(): iterable
    {
        yield 'faixa fora da lista' => [30, null, 'Escolha 20, 50 ou 100 GB.'];
        yield 'motivo grande' => [20, str_repeat('a', 301), 'O motivo pode ter até 300 caracteres.'];
    }

    #[TestDox('Com as opções já liberadas (Master ou 97% de uso) o pedido é recusado')]
    public function testJaLiberadas(): void
    {
        $pedidos = $this->createMock(PedidoDeEspacoRepository::class);
        $pedidos->expects(self::never())->method('salvar');
        $useCase = $this->useCase($pedidos, $this->gb(9.8), null, $eventos);

        $this->expectExceptionMessage('As opções de espaço extra já estão liberadas para o seu escritório.');
        $useCase->executar($this->quemDeTeste(primario: true), 20, null);
    }

    #[TestDox('Situação do escritório: nenhum, pendente com GB e data, liberado com origem (liberação vale mais que pendente)')]
    public function testSituacaoDoEscritorio(): void
    {
        $semPedido = $this->createStub(PedidoDeEspacoRepository::class);
        $nenhum = (new VerPedidoDeEspacoDoEscritorioUseCase($semPedido, $this->situacaoDeTeste($this->gb(2))))->executar($this->quemDeTeste(primario: true));
        self::assertSame(PedidoDeEspacoDoEscritorioView::NENHUM, $nenhum->estado);

        $comPendente = $this->createStub(PedidoDeEspacoRepository::class);
        $comPendente->method('pendenteDoEscritorio')->willReturn($this->pedido());
        $pendente = (new VerPedidoDeEspacoDoEscritorioUseCase($comPendente, $this->situacaoDeTeste($this->gb(2))))->executar($this->quemDeTeste(primario: true));
        self::assertTrue($pendente->pendente());
        self::assertSame(50, $pendente->gbDesejado);
        self::assertNotNull($pendente->pedidoEm);

        $espaco = new EspacoDoEscritorio($this->escritorioDeTeste(), $this->planosDeTeste()->free());
        $espaco->liberarOpcoes(OrigemDaLiberacao::Master, new \DateTimeImmutable());
        $liberado = (new VerPedidoDeEspacoDoEscritorioUseCase($comPendente, $this->situacaoDeTeste($this->gb(2), $espaco)))->executar($this->quemDeTeste(primario: true));
        self::assertTrue($liberado->liberado());
        self::assertSame(OrigemDaLiberacao::Master, $liberado->liberadaPor);
    }

    private function pedido(): PedidoDeEspaco
    {
        return new PedidoDeEspaco($this->escritorioDeTeste(), null, 50, null, $this->gb(2), $this->gb(10));
    }

    private function useCase(PedidoDeEspacoRepository $pedidos, int $bytes, ?EspacoDoEscritorio $espaco, ?EventosDoEspacoAposConfirmar &$eventos): SolicitarMaisEspacoUseCase
    {
        $despachante = $this->createStub(EventDispatcherInterface::class);
        $despachante->method('dispatch')->willReturnCallback(function (object $e): object {
            $this->despachados[] = $e;

            return $e;
        });
        $eventos = new EventosDoEspacoAposConfirmar($despachante);
        $usuarios = $this->createStub(UserRepository::class);
        $usuarios->method('findComPapelGlobal')->willReturn([]);
        $aviso = new AvisoAoMaster($usuarios, $this->createStub(UserTenantRepository::class), $this->createStub(NotificacaoService::class), new NullLogger());
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/contribua');

        return new SolicitarMaisEspacoUseCase($pedidos, $this->situacaoDeTeste($bytes, $espaco), $aviso, $urls, $eventos, new MockClock());
    }

    private function confirmar(EventosDoEspacoAposConfirmar $eventos): void
    {
        $eventos->aoResponder(new ResponseEvent($this->createStub(HttpKernelInterface::class), Request::create('/'), HttpKernelInterface::MAIN_REQUEST, new Response()));
    }
}
