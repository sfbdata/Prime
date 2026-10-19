<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Entity\Auth\User;
use App\Master\DTO\AlternarRecursoInput;
use App\Master\DTO\EstadoDoRecursoOutput;
use App\Master\Entity\RecursoEstado;
use App\Master\Exception\RecursoRecusado;
use App\Master\Repository\RecursoEstadoRepository;
use App\Master\UseCase\AlternarRecursoUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Ligar e desligar uma tela ou função (spec perfis-1.2-telas-e-recursos, R1, R3, R4 e §3).
 */
#[CoversClass(AlternarRecursoUseCase::class)]
#[CoversClass(AlternarRecursoInput::class)]
#[CoversClass(EstadoDoRecursoOutput::class)]
final class AlternarRecursoUseCaseTest extends TestCase
{
    private RecursoEstadoRepository&MockObject $repositorio;
    private AlternarRecursoUseCase $useCase;
    private User $master;

    protected function setUp(): void
    {
        $this->repositorio = $this->createMock(RecursoEstadoRepository::class);
        $this->useCase = new AlternarRecursoUseCase($this->repositorio, new MockClock('2026-10-19 10:00:00'));
        $this->master = (new User())->setRoles(['ROLE_SUPER_ADMIN']);
    }

    #[TestDox('desligar grava motivo, data, quem e quando, e devolve o estado novo')]
    public function testDesligaComMotivoEData(): void
    {
        $gravado = null;
        $this->repositorio->method('buscarPorChave')->willReturn(null);
        $this->repositorio->expects($this->once())->method('salvar')
            ->with($this->callback(function (RecursoEstado $e) use (&$gravado): bool {
                $gravado = $e;

                return true;
            }), true);

        $saida = $this->useCase->executar(AlternarRecursoInput::doJson($this->master, 'dashboard', ['ativo' => false, 'motivo' => ' Revisão ética ', 'ate' => '2026-10-25'], self::hoje()) ?? self::fail());

        self::assertInstanceOf(RecursoEstado::class, $gravado);
        self::assertSame('dashboard', $gravado->getChave());
        self::assertFalse($gravado->isAtivo());
        self::assertSame('Revisão ética', $gravado->getMotivo());
        self::assertSame('2026-10-25', $gravado->getAte()?->format('Y-m-d'));
        self::assertSame($this->master, $gravado->getAlteradoPor());
        self::assertSame('2026-10-19 10:00', $gravado->getAlteradoEm()->format('Y-m-d H:i'));
        self::assertSame(['chave' => 'dashboard', 'ativo' => false, 'motivo' => 'Revisão ética', 'ate' => '2026-10-25'], $saida->paraJson());
    }

    #[TestDox('religar limpa motivo e data da linha existente')]
    public function testReligaLimpa(): void
    {
        $estado = new RecursoEstado('metas');
        $estado->desativar('Tela em preparação', new \DateTimeImmutable('2026-11-01'), null, new \DateTimeImmutable('2026-10-01'));
        $this->repositorio->method('buscarPorChave')->with('metas')->willReturn($estado);
        $this->repositorio->expects($this->once())->method('salvar')->with($estado, true);

        $saida = $this->useCase->executar(new AlternarRecursoInput($this->master, 'metas', true));

        self::assertTrue($estado->isAtivo());
        self::assertNull($estado->getMotivo());
        self::assertNull($estado->getAte());
        self::assertSame(['chave' => 'metas', 'ativo' => true, 'motivo' => null, 'ate' => null], $saida->paraJson());
    }

    #[TestDox('religar o que já está ativo (sem linha) não grava nada')]
    public function testReligarSemLinhaNaoGrava(): void
    {
        $this->repositorio->method('buscarPorChave')->willReturn(null);
        $this->repositorio->expects($this->never())->method('salvar');

        self::assertTrue($this->useCase->executar(new AlternarRecursoInput($this->master, 'tema', true))->ativo);
    }

    #[TestDox('tela essencial é recusada (R3)')]
    public function testEssencialRecusado(): void
    {
        $this->repositorio->expects($this->never())->method('salvar');
        $this->expectException(RecursoRecusado::class);
        $this->expectExceptionMessage('Tela essencial: não pode ser desativada.');

        $this->useCase->executar(new AlternarRecursoInput($this->master, 'lista', false));
    }

    #[TestDox('chave fora do catálogo é recusada (R1)')]
    public function testChaveDesconhecidaRecusada(): void
    {
        $this->repositorio->expects($this->never())->method('salvar');
        $this->expectException(RecursoRecusado::class);

        $this->useCase->executar(new AlternarRecursoInput($this->master, 'inexistente', false));
    }

    #[TestDox('data de volta no passado vira "sem data", como a inválida; hoje ainda vale (R4)')]
    public function testDataNoPassadoViraSemData(): void
    {
        $ontem = AlternarRecursoInput::doJson(null, 'dashboard', ['ativo' => false, 'ate' => '2026-10-18'], self::hoje()) ?? self::fail();
        self::assertNull($ontem->ate);

        $hoje = AlternarRecursoInput::doJson(null, 'dashboard', ['ativo' => false, 'ate' => '2026-10-19'], self::hoje()) ?? self::fail();
        self::assertSame('2026-10-19', $hoje->ate?->format('Y-m-d'));

        $this->repositorio->method('buscarPorChave')->willReturn(null);
        self::assertFalse($this->useCase->executar($ontem)->ativo, 'desligar nunca resulta em "Ativo" sem explicação');
    }

    #[TestDox('o corpo JSON é normalizado: sem "ativo" é recusado, motivo vazio vira nulo, data inválida vira "sem data"')]
    public function testNormalizacaoDoCorpo(): void
    {
        self::assertNull(AlternarRecursoInput::doJson(null, 'dashboard', [], self::hoje()));
        self::assertNull(AlternarRecursoInput::doJson(null, 'dashboard', ['ativo' => 'false'], self::hoje()));

        $input = AlternarRecursoInput::doJson(null, 'dashboard', ['ativo' => false, 'motivo' => '   ', 'ate' => '2026-02-31'], self::hoje()) ?? self::fail();
        self::assertNull($input->motivo);
        self::assertNull($input->ate);

        $longo = AlternarRecursoInput::doJson(null, 'dashboard', ['ativo' => false, 'motivo' => str_repeat('a', 400), 'ate' => '25/10/2026'], self::hoje()) ?? self::fail();
        self::assertSame(RecursoEstado::MOTIVO_MAXIMO, mb_strlen((string) $longo->motivo));
        self::assertNull($longo->ate);
    }

    private static function hoje(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-19 10:00:00');
    }
}
