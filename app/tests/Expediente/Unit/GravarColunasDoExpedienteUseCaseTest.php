<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Unit;

use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Expediente\Exception\ColunasDoExpedienteInvalidasException;
use App\Expediente\UseCase\GravarColunasDoExpedienteUseCase;
use App\Expediente\UseCase\ObterColunasDoExpedienteUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Colunas da Lista do Expediente por usuário: o UseCase grava só um mapa {coluna da tabela: bool}, apaga com
 * `null` (Restaurar padrão) e recusa qualquer outro formato sem tocar no repositório.
 */
#[CoversClass(GravarColunasDoExpedienteUseCase::class)]
#[CoversClass(ObterColunasDoExpedienteUseCase::class)]
final class GravarColunasDoExpedienteUseCaseTest extends TestCase
{
    private const CHAVE = 'expediente.colunas';

    private Tenant $tenant;
    private User $user;
    private PreferenciaDoUsuarioRepository&MockObject $preferencias;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->user = new User();
        $this->preferencias = $this->createMock(PreferenciaDoUsuarioRepository::class);
    }

    #[TestDox('grava o mapa de colunas do usuário no escritório, na chave expediente.colunas')]
    public function testGravaOMapa(): void
    {
        $mapa = ['nup' => true, 'reu' => false, 'metas' => false, 'sit' => true];
        $this->preferencias->expects(self::once())->method('gravar')->with($this->tenant, $this->user, self::CHAVE, $mapa);
        $this->preferencias->expects(self::never())->method('apagarDoUsuario');

        (new GravarColunasDoExpedienteUseCase($this->preferencias))->executar($this->tenant, $this->user, $mapa);
    }

    #[TestDox('null apaga o ajuste do usuário (volta ao padrão) e não grava nada')]
    public function testNullApaga(): void
    {
        $this->preferencias->expects(self::once())->method('apagarDoUsuario')->with($this->tenant, $this->user, [self::CHAVE]);
        $this->preferencias->expects(self::never())->method('gravar');

        (new GravarColunasDoExpedienteUseCase($this->preferencias))->executar($this->tenant, $this->user, null);
    }

    /** @return iterable<string, array{mixed}> */
    public static function formatosInvalidos(): iterable
    {
        yield 'texto' => ['reu'];
        yield 'número' => [3];
        yield 'booleano' => [true];
        yield 'lista' => [[true, false]];
        yield 'coluna que a tabela não tem' => [['reu' => false, 'senha' => false]];
        yield 'chave com maiúscula' => [['Reu' => false]];
        yield 'valor que não é booleano' => [['reu' => 'false']];
        yield 'valor numérico' => [['reu' => 0]];
        yield 'valor objeto' => [['reu' => ['x' => true]]];
        yield 'mais de 40 entradas' => [array_fill_keys(array_map(static fn (int $i): string => 'c' . $i, range(1, 41)), true)];
    }

    #[DataProvider('formatosInvalidos')]
    #[TestDox('recusa formato inválido ($_dataName) e não toca no repositório')]
    public function testRecusaFormatoInvalido(mixed $colunas): void
    {
        $this->preferencias->expects(self::never())->method('gravar');
        $this->preferencias->expects(self::never())->method('apagarDoUsuario');

        $this->expectException(ColunasDoExpedienteInvalidasException::class);
        (new GravarColunasDoExpedienteUseCase($this->preferencias))->executar($this->tenant, $this->user, $colunas);
    }

    #[TestDox('leitura: devolve o mapa salvo do usuário no escritório')]
    public function testObterDevolveOMapa(): void
    {
        $this->preferencias->method('valoresDoUsuario')->with($this->tenant, $this->user, [self::CHAVE])
            ->willReturn([self::CHAVE => ['reu' => false]]);

        self::assertSame(['reu' => false], (new ObterColunasDoExpedienteUseCase($this->preferencias))->executar($this->tenant, $this->user));
    }

    #[TestDox('leitura: sem ajuste, ou com valor que a gravação recusaria, devolve null (padrão)')]
    public function testObterSemAjusteOuInvalidoDevolveNull(): void
    {
        $this->preferencias->method('valoresDoUsuario')->willReturnOnConsecutiveCalls([], [self::CHAVE => ['senha' => true]]);
        $obter = new ObterColunasDoExpedienteUseCase($this->preferencias);

        self::assertNull($obter->executar($this->tenant, $this->user));
        self::assertNull($obter->executar($this->tenant, $this->user));
    }
}
