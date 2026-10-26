<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit;

use App\Contribua\Entity\BonusDeEspaco;
use App\Contribua\Repository\BonusDeEspacoRepository;
use App\Contribua\UseCase\ConcederBonusDeEspacoPorIndicacaoUseCase;
use App\Entity\Auth\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(ConcederBonusDeEspacoPorIndicacaoUseCase::class)]
#[CoversClass(BonusDeEspaco::class)]
final class ConcederBonusDeEspacoPorIndicacaoUseCaseTest extends TestCase
{
    private BonusDeEspacoRepository&MockObject $bonus;
    private UserRepository&MockObject $usuarios;

    protected function setUp(): void
    {
        $this->bonus = $this->createMock(BonusDeEspacoRepository::class);
        $this->bonus->method('emTransacaoTravada')->willReturnCallback(static fn (int $indicacao, \Closure $trabalho): mixed => $trabalho());
        $this->usuarios = $this->createMock(UserRepository::class);
    }

    private function caso(): ConcederBonusDeEspacoPorIndicacaoUseCase
    {
        return new ConcederBonusDeEspacoPorIndicacaoUseCase($this->bonus, $this->usuarios, new MockClock('2026-10-26 10:00:00'));
    }

    private function contaConfirmada(bool $confirmada): User
    {
        $conta = new User();
        if (!$confirmada) {
            $conta->marcarEmailAConfirmar(new \DateTimeImmutable());
        }

        return $conta;
    }

    #[TestDox('e-mail confirmado e indicação ainda sem bônus: concede 5 GB por 30 dias a partir de agora')]
    public function testConcede(): void
    {
        $this->usuarios->method('find')->with(5)->willReturn($this->contaConfirmada(true));
        $this->bonus->method('jaConcedidoPorIndicacao')->with(9)->willReturn(false);
        $this->bonus->expects($this->once())->method('novoDaIndicacao')
            ->with(3, 9, $this->callback(static fn (\DateTimeImmutable $agora): bool => $agora->format('Y-m-d H:i:s') === '2026-10-26 10:00:00'));

        self::assertTrue($this->caso()->executar(5, 9, 3));
    }

    #[TestDox('as constantes: 5 GB e 30 dias corridos, origem indicação')]
    public function testConstantes(): void
    {
        $bonus = BonusDeEspaco::daIndicacao(new \App\Entity\Tenant\Tenant(), (new \ReflectionClass(\App\Indicacao\Entity\Indicacao::class))->newInstanceWithoutConstructor(), new \DateTimeImmutable('2026-10-26 10:00:00'));

        self::assertSame(5, $bonus->getGigabytes());
        self::assertSame('2026-11-25 10:00:00', $bonus->getExpiraEm()->format('Y-m-d H:i:s'));
        self::assertSame('indicacao', $bonus->getOrigem());
    }

    #[TestDox('e-mail ainda não confirmado: não concede')]
    public function testSemEmailConfirmado(): void
    {
        $this->usuarios->method('find')->willReturn($this->contaConfirmada(false));
        $this->bonus->expects($this->never())->method('novoDaIndicacao');

        self::assertFalse($this->caso()->executar(5, 9, 3));
    }

    #[TestDox('usuário inexistente: não concede')]
    public function testUsuarioInexistente(): void
    {
        $this->usuarios->method('find')->willReturn(null);
        $this->bonus->expects($this->never())->method('novoDaIndicacao');

        self::assertFalse($this->caso()->executar(404, 9, 3));
    }

    #[TestDox('indicação que já tem bônus: idempotente, não concede de novo')]
    public function testIdempotente(): void
    {
        $this->usuarios->method('find')->willReturn($this->contaConfirmada(true));
        $this->bonus->method('jaConcedidoPorIndicacao')->willReturn(true);
        $this->bonus->expects($this->never())->method('novoDaIndicacao');

        self::assertFalse($this->caso()->executar(5, 9, 3));
    }

    #[TestDox('corrida que estoura a UNIQUE: devolve falso em vez de lançar')]
    public function testCorridaNaUnique(): void
    {
        $this->usuarios->method('find')->willReturn($this->contaConfirmada(true));
        $this->bonus = $this->createMock(BonusDeEspacoRepository::class);
        $this->bonus->method('emTransacaoTravada')->willThrowException($this->createStub(UniqueConstraintViolationException::class));

        self::assertFalse($this->caso()->executar(5, 9, 3));
    }
}
