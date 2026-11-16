<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit;

use App\Contribua\Entity\EspacoDoEscritorio;
use App\Contribua\Enum\OrigemDaLiberacao;
use App\Contribua\Event\EventosDoEspacoAposConfirmar;
use App\Contribua\Event\OpcoesDeEspacoForamLiberadas;
use App\Contribua\Repository\EspacoDoEscritorioRepository;
use App\Contribua\UseCase\RegistrarLiberacaoAutomaticaUseCase;
use App\Tests\Contribua\Doubles\CriaSituacaoDoEspaco;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(RegistrarLiberacaoAutomaticaUseCase::class)]
final class RegistrarLiberacaoAutomaticaUseCaseTest extends TestCase
{
    use CriaSituacaoDoEspaco;

    /** @var list<object> */
    private array $despachados = [];

    #[TestDox('Abaixo de 97% não grava nada nem despacha')]
    public function testAbaixoDe97(): void
    {
        $espacos = $this->createMock(EspacoDoEscritorioRepository::class);
        $espacos->expects(self::never())->method('salvar');

        self::assertFalse($this->useCase($this->gb(9.6), null, $espacos, $eventos)->executar(7));
        $this->confirmar($eventos);
        self::assertSame([], $this->despachados);
    }

    #[TestDox('A 97% grava a marca automática uma vez e despacha o evento (Auto, sem pedido)')]
    public function testGravaUmaVez(): void
    {
        $espaco = new EspacoDoEscritorio($this->escritorioDeTeste(), $this->planosDeTeste()->free());
        $espacos = $this->createMock(EspacoDoEscritorioRepository::class);
        $espacos->method('doTenantOuNovo')->willReturn($espaco);
        $espacos->expects(self::once())->method('salvar');
        $useCase = $this->useCase($this->gb(9.7), $espaco, $espacos, $eventos);

        self::assertTrue($useCase->executar(7));
        self::assertFalse($useCase->executar(7), 'idempotente: a segunda chamada não grava nem despacha');
        $this->confirmar($eventos);

        self::assertSame(OrigemDaLiberacao::Auto, $espaco->getOpcoesLiberadasPor());
        self::assertCount(1, $this->despachados);
        self::assertInstanceOf(OpcoesDeEspacoForamLiberadas::class, $this->despachados[0]);
        self::assertSame([7, OrigemDaLiberacao::Auto, null], [$this->despachados[0]->tenantId, $this->despachados[0]->origem, $this->despachados[0]->pedidoId]);
    }

    #[TestDox('Escritório já liberado pelo Master não ganha a marca automática')]
    public function testMasterJaLiberou(): void
    {
        $espaco = new EspacoDoEscritorio($this->escritorioDeTeste(), $this->planosDeTeste()->free());
        $espaco->liberarOpcoes(OrigemDaLiberacao::Master, new \DateTimeImmutable());
        $espacos = $this->createMock(EspacoDoEscritorioRepository::class);
        $espacos->method('doTenantOuNovo')->willReturn($espaco);
        $espacos->expects(self::never())->method('salvar');

        self::assertFalse($this->useCase($this->gb(10), $espaco, $espacos, $eventos)->executar(7));
        self::assertSame(OrigemDaLiberacao::Master, $espaco->getOpcoesLiberadasPor());
    }

    private function useCase(int $bytes, ?EspacoDoEscritorio $espaco, EspacoDoEscritorioRepository $espacos, ?EventosDoEspacoAposConfirmar &$eventos): RegistrarLiberacaoAutomaticaUseCase
    {
        $despachante = $this->createStub(EventDispatcherInterface::class);
        $despachante->method('dispatch')->willReturnCallback(function (object $e): object {
            $this->despachados[] = $e;

            return $e;
        });
        $eventos = new EventosDoEspacoAposConfirmar($despachante);

        return new RegistrarLiberacaoAutomaticaUseCase($this->situacaoDeTeste($bytes, $espaco), $espacos, $this->planosDeTeste(), $eventos, new MockClock());
    }

    private function confirmar(EventosDoEspacoAposConfirmar $eventos): void
    {
        $eventos->aoResponder(new ResponseEvent($this->createStub(HttpKernelInterface::class), Request::create('/'), HttpKernelInterface::MAIN_REQUEST, new Response()));
    }
}
