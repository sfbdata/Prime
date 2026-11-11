<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\SolicitarCodigoDaAssembleiaInput;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Repository\UnidadeDaAssembleiaRepository;
use App\Voto\Service\ChaveDoCodigoDaAssembleia;
use App\Voto\Service\CorreioDoCodigoDaAssembleia;
use App\Voto\Service\TokenDaUnidade;
use App\Voto\Service\TransacaoDoVoto;
use App\Voto\UseCase\SolicitarCodigoDaAssembleiaUseCase;
use App\Voto\UseCase\ConfirmarCodigoDaAssembleiaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(SolicitarCodigoDaAssembleiaUseCase::class)]
#[CoversClass(ConfirmarCodigoDaAssembleiaUseCase::class)]
final class AcessoPorCodigoDaAssembleiaTest extends TestCase
{
    public function testEnviaSoCodigoDeSeisNumerosEConfirmaUmaVez(): void
    {
        $a = new Assembleia(new Tenant(), 'AGO', PesoDoVoto::Unidade, 'Gestora');
        $u = new UnidadeDaAssembleia($a, '101', str_repeat('a', 32), str_repeat('b', 64), email: 'morador@example.com');
        $repo = $this->createMock(UnidadeDaAssembleiaRepository::class);
        $repo->method('localizarParaCodigo')->willReturn($u);
        $repo->method('buscarDaAssembleiaTravada')->willReturn($u);
        $transacao = new class () implements TransacaoDoVoto {
            public function executar(callable $trabalho): mixed
            {
                return $trabalho();
            }
            public function gravar(): void
            {
            }
        };
        $chave = new ChaveDoCodigoDaAssembleia('segredo-teste');
        $clock = new MockClock('2026-11-11 10:00:00');
        $codigo = '';
        $correio = $this->createMock(CorreioDoCodigoDaAssembleia::class);
        $correio->expects(self::once())->method('enviar')->with($u, self::callback(function (string $valor) use (&$codigo): bool {
            $codigo = $valor;

            return preg_match('/^[0-9]{6}$/', $valor) === 1;
        }));
        $solicitar = new SolicitarCodigoDaAssembleiaUseCase($repo, $transacao, $chave, $correio, $clock, new \Psr\Log\NullLogger());
        $input = new SolicitarCodigoDaAssembleiaInput('Morador', '101', '', 'morador@example.com', str_repeat('c', 32));
        $solicitar->executar($a, $input);
        $tokens = new TokenDaUnidade('segredo-teste');
        $confirmar = new ConfirmarCodigoDaAssembleiaUseCase($repo, $transacao, $chave, $tokens, $clock);

        self::assertSame($tokens->token($u->getSemente()), $confirmar->executar($a, $input, $codigo));
        self::assertNull($confirmar->executar($a, $input, $codigo));
    }

    public function testSemCorrespondenciaNaoEnviaEmail(): void
    {
        $repo = $this->createStub(UnidadeDaAssembleiaRepository::class);
        $repo->method('localizarParaCodigo')->willReturn(null);
        $correio = $this->createMock(CorreioDoCodigoDaAssembleia::class);
        $correio->expects(self::never())->method('enviar');
        $transacao = $this->createMock(TransacaoDoVoto::class);
        $transacao->expects(self::never())->method('executar');
        $usecase = new SolicitarCodigoDaAssembleiaUseCase($repo, $transacao, new ChaveDoCodigoDaAssembleia('teste'), $correio, new MockClock(), new \Psr\Log\NullLogger());

        $usecase->executar(new Assembleia(new Tenant(), 'AGO', PesoDoVoto::Unidade, 'Gestora'), new SolicitarCodigoDaAssembleiaInput('Pessoa', '101', '', 'inexistente@example.com', str_repeat('a', 32)));
    }
}
