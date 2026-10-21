<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit\Central;

use App\Entity\Auth\User;
use App\Master\DTO\RespostaDaCentralOutput;
use App\Master\Entity\CentralMasterEstado;
use App\Master\Entity\CentralMasterResposta;
use App\Master\Notificacoes\OrigensDaCentral;
use App\Master\Repository\CentralMasterEstadoRepository;
use App\Master\Repository\CentralMasterRespostaRepository;
use App\Master\UseCase\AbrirMensagemDaCentralUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(AbrirMensagemDaCentralUseCase::class)]
#[CoversClass(RespostaDaCentralOutput::class)]
final class AbrirMensagemDaCentralUseCaseTest extends TestCase
{
    #[TestDox('Abrir marca como lida para este Master e traz o histórico: "minha" só na resposta dele')]
    public function testAbrirMarcaLidaETrazHistorico(): void
    {
        $eu = $this->usuario(1, 'Master Um');
        $outro = $this->usuario(2, 'Master Dois');
        $estado = new CentralMasterEstado($eu, 'sugestao', 1, 7);
        $estados = $this->createMock(CentralMasterEstadoRepository::class);
        $estados->method('daMensagemOuNova')->willReturn($estado);
        $estados->expects(self::once())->method('salvar')->with($estado, true);
        $respostas = $this->createStub(CentralMasterRespostaRepository::class);
        $respostas->method('daMensagem')->willReturn([
            new CentralMasterResposta('sugestao', 1, 7, 'Primeira', $outro, 'email', 'ana@exemplo.com', 10),
            new CentralMasterResposta('sugestao', 1, 7, 'Segunda', $eu, 'email', 'ana@exemplo.com', 11),
        ]);

        $aberta = $this->useCase($estados, $respostas)->executar($eu, 'sugestao', 1);

        self::assertNotNull($aberta);
        self::assertTrue($estado->isLida());
        self::assertTrue($aberta->item->lida);
        self::assertTrue($aberta->item->respondida);
        self::assertSame([[false, 'Master Dois'], [true, 'Master Um']], array_map(static fn (RespostaDaCentralOutput $r): array => [$r->minha, $r->porNome], $aberta->historico));
    }

    #[TestDox('Mensagem que sumiu da origem: nulo, sem gravar nada')]
    public function testMensagemQueSumiu(): void
    {
        $estados = $this->createMock(CentralMasterEstadoRepository::class);
        $estados->expects(self::never())->method('salvar');

        self::assertNull($this->useCase($estados, $this->createStub(CentralMasterRespostaRepository::class))->executar(new User(), 'sugestao', 99));
        self::assertNull($this->useCase($estados, $this->createStub(CentralMasterRespostaRepository::class))->executar(new User(), 'inexistente', 1));
    }

    private function useCase(CentralMasterEstadoRepository $estados, CentralMasterRespostaRepository $respostas): AbrirMensagemDaCentralUseCase
    {
        $origens = new OrigensDaCentral([MensagensDeTeste::origem('sugestao', [MensagensDeTeste::mensagem('sugestao', 1)])]);

        return new AbrirMensagemDaCentralUseCase($origens, $estados, $respostas, new MockClock('2026-10-21 10:00'));
    }

    private function usuario(int $id, string $nome): User
    {
        $user = (new User())->setFullName($nome)->setEmail('m' . $id . '@exemplo.com');
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }
}
