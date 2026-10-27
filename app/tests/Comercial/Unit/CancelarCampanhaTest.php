<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Comercial\Entity\CampanhaDeEmail;
use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\Repository\CampanhaDeEmailRepository;
use App\Comercial\Repository\EnvioDeEmailRepository;
use App\Comercial\UseCase\AbrirCampanhaDoRelatorioUseCase;
use App\Comercial\UseCase\EncerrarCampanhaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * "Cancelar envio" do Relatório sobre o EncerrarCampanhaUseCase: só onde ele aceita, com o motivo da tela e o autor na
 * trilha; o console continua com "Encerrada pelo console" e sem autor.
 */
#[CoversClass(EncerrarCampanhaUseCase::class)]
#[CoversClass(AbrirCampanhaDoRelatorioUseCase::class)]
final class CancelarCampanhaTest extends TestCase
{
    /** @return iterable<string, array{string, bool, bool}> */
    public static function estados(): iterable
    {
        foreach (CampanhaDeEmail::ATIVAS as $ativa) {
            yield $ativa => [$ativa, false, true];
        }
        yield 'enviada com fila' => [CampanhaDeEmail::ENVIADA, true, true];
        yield 'enviada sem fila' => [CampanhaDeEmail::ENVIADA, false, false];
        yield 'cancelada' => [CampanhaDeEmail::CANCELADA, true, false];
        yield 'teste' => ['teste', true, false];
    }

    #[TestDox('O botão aparece só onde o EncerrarCampanha aceita: $estado')]
    #[DataProvider('estados')]
    public function testCancelavel(string $estado, bool $temFila, bool $esperado): void
    {
        self::assertSame($esperado, AbrirCampanhaDoRelatorioUseCase::cancelavel($estado, static fn (): bool => $temFila));
    }

    #[TestDox('Console sem mudança: o motivo é "Encerrada pelo console" e a trilha vai sem autor')]
    public function testConsole(): void
    {
        $campanhas = $this->campanhas();
        $campanhas->expects(self::once())->method('encerrar')->with(7, EncerrarCampanhaUseCase::MOTIVO)->willReturn(['publico' => 'indiv', 'frequencia' => 'unica', 'total' => 2]);
        $campanhas->expects(self::once())->method('auditar')->with(7, CampanhaDeEmailRepository::ENCERRADA, self::anything(), null, null, self::anything());

        self::assertSame('Campanha 7 encerrada. 3 e-mail(s) que estavam na fila foram cancelados.', $this->caso($campanhas)->executar(7));
        self::assertSame('Encerrada pelo console', EncerrarCampanhaUseCase::MOTIVO);
    }

    #[TestDox('Pela tela: "Cancelada pelo Master", também na enviada com fila, e o Master na trilha')]
    public function testPelaTela(): void
    {
        $campanhas = $this->campanhas();
        $campanhas->method('encerrar')->willReturn(null);
        $campanhas->expects(self::once())->method('encerrarEnviadaComFila')->with(7, 'Cancelada pelo Master')->willReturn(['publico' => 'indiv', 'frequencia' => 'unica', 'total' => 2]);
        $campanhas->expects(self::once())->method('auditar')->with(7, CampanhaDeEmailRepository::ENCERRADA, self::anything(), 11, 'master@x.com', self::anything());

        $this->caso($campanhas)->executar(7, EncerrarCampanhaUseCase::MOTIVO_DO_MASTER, 11, 'master@x.com');
    }

    #[TestDox('Encerrada ou inexistente: CampanhaRecusada com a mensagem, sem trilha')]
    public function testNaoAtiva(): void
    {
        $campanhas = $this->campanhas();
        $campanhas->method('encerrar')->willReturn(null);
        $campanhas->method('encerrarEnviadaComFila')->willReturn(null);
        $campanhas->expects(self::never())->method('auditar');

        $this->expectException(CampanhaRecusada::class);
        $this->expectExceptionMessage(EncerrarCampanhaUseCase::NAO_ATIVA);
        $this->caso($campanhas)->executar(7, EncerrarCampanhaUseCase::MOTIVO_DO_MASTER);
    }

    #[TestDox('O estado ao lado do assunto segue o dc (L713)')]
    public function testSituacao(): void
    {
        self::assertSame('E-mail de teste', AbrirCampanhaDoRelatorioUseCase::situacao('teste', 'unica'));
        self::assertSame('Recorrente · mensal', AbrirCampanhaDoRelatorioUseCase::situacao('recorrente', 'mensal'));
        self::assertSame('Agendada', AbrirCampanhaDoRelatorioUseCase::situacao('agendada', 'unica'));
        self::assertSame('Na fila de envio', AbrirCampanhaDoRelatorioUseCase::situacao('na_fila', 'unica'));
        self::assertSame('Cancelada', AbrirCampanhaDoRelatorioUseCase::situacao('cancelada', 'unica'));
    }

    private function campanhas(): CampanhaDeEmailRepository&MockObject
    {
        $campanhas = $this->createMock(CampanhaDeEmailRepository::class);
        $campanhas->method('emTransacao')->willReturnCallback(static fn (\Closure $trabalho): mixed => $trabalho());

        return $campanhas;
    }

    private function caso(CampanhaDeEmailRepository $campanhas): EncerrarCampanhaUseCase
    {
        $envios = $this->createStub(EnvioDeEmailRepository::class);
        $envios->method('cancelarNaFilaDaOrigem')->willReturn(3);
        $relogio = $this->createStub(ClockInterface::class);
        $relogio->method('now')->willReturn(new \DateTimeImmutable('2026-10-21 10:00'));

        return new EncerrarCampanhaUseCase($campanhas, $envios, $relogio);
    }
}
