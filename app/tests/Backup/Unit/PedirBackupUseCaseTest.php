<?php

declare(strict_types=1);

namespace App\Tests\Backup\Unit;

use App\Backup\Entity\PedidoDeBackup;
use App\Backup\Enum\SituacaoDoBackup;
use App\Backup\Exception\PedidoDeBackupNaoPermitido;
use App\Backup\Message\GerarBackup;
use App\Backup\Repository\PedidoDeBackupRepository;
use App\Backup\UseCase\PedirBackupUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(PedirBackupUseCase::class)]
final class PedirBackupUseCaseTest extends TestCase
{
    private PedidoDeBackupRepository&MockObject $pedidos;
    private Connection&MockObject $conexao;
    private MessageBusInterface&MockObject $bus;
    private PedirBackupUseCase $useCase;
    private User $usuario;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->pedidos = $this->createMock(PedidoDeBackupRepository::class);
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->conexao = $this->createMock(Connection::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->conexao);
        $this->useCase = new PedirBackupUseCase($this->pedidos, $em, $this->bus, new MockClock('2026-11-23 10:00:00'));

        $this->usuario = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($this->usuario, 7);
        $this->tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($this->tenant, 3);
    }

    #[TestDox('Cria o pedido, grava com flush e despacha GerarBackup com o id')]
    public function testCriaEDespacha(): void
    {
        $ordem = [];
        $this->conexao->expects(self::once())->method('beginTransaction')->willReturnCallback(static function () use (&$ordem): void {
            $ordem[] = 'begin';
        });
        $this->conexao->expects(self::once())->method('executeStatement')
            ->with(self::stringContains('pg_advisory_xact_lock'), [PedirBackupUseCase::CLASSE_TRAVA, '3:7'])
            ->willReturnCallback(static function () use (&$ordem): int {
                $ordem[] = 'trava';

                return 0;
            });
        $this->conexao->expects(self::once())->method('commit')->willReturnCallback(static function () use (&$ordem): void {
            $ordem[] = 'commit';
        });
        $this->conexao->expects(self::never())->method('rollBack');
        $this->pedidos->method('temAbertoDaPessoa')->with(3, 7)->willReturnCallback(static function () use (&$ordem): bool {
            $ordem[] = 'checagem';

            return false;
        });
        $this->pedidos->expects(self::once())->method('quantosDaPessoaDesde')
            ->with(3, 7, self::callback(static fn (\DateTimeImmutable $d): bool => $d->getTimestamp() === (new \DateTimeImmutable('2026-11-23 10:00:00', new \DateTimeZone('UTC')))->modify('-24 hours')->getTimestamp()))
            ->willReturn(0);
        $this->pedidos->expects(self::once())->method('salvar')->willReturnCallback(
            static function (PedidoDeBackup $p, bool $flush): void {
                self::assertTrue($flush);
                (new \ReflectionProperty(PedidoDeBackup::class, 'id'))->setValue($p, 55);
            },
        );
        $this->bus->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn (GerarBackup $m): bool => 55 === $m->pedidoId))
            ->willReturnCallback(static function (object $m) use (&$ordem): Envelope {
                $ordem[] = 'dispatch';

                return new Envelope($m);
            });

        $pedido = $this->useCase->executar($this->usuario, $this->tenant);

        self::assertSame(['begin', 'trava', 'checagem', 'commit', 'dispatch'], $ordem, 'trava antes das checagens; dispatch só depois do commit');

        self::assertSame(SituacaoDoBackup::Pedido, $pedido->getSituacao());
        self::assertSame($this->tenant, $pedido->getTenant());
    }

    #[TestDox('Recusa quando já existe pedido aberto, sem criar nem despachar')]
    public function testRecusaComPedidoAberto(): void
    {
        $this->pedidos->method('temAbertoDaPessoa')->willReturn(true);
        $this->conexao->expects(self::once())->method('rollBack');
        $this->pedidos->expects(self::never())->method('salvar');
        $this->bus->expects(self::never())->method('dispatch');

        $this->expectException(PedidoDeBackupNaoPermitido::class);
        $this->expectExceptionMessage('Você já tem um backup sendo preparado. Aguarde ele ficar pronto.');
        $this->useCase->executar($this->usuario, $this->tenant);
    }

    #[TestDox('Recusa o segundo pedido nas últimas 24 horas')]
    public function testRecusaNoLimiteDoDia(): void
    {
        $this->pedidos->method('temAbertoDaPessoa')->willReturn(false);
        $this->pedidos->method('quantosDaPessoaDesde')->willReturn(1);
        $this->conexao->expects(self::once())->method('rollBack');
        $this->pedidos->expects(self::never())->method('salvar');
        $this->bus->expects(self::never())->method('dispatch');

        $this->expectException(PedidoDeBackupNaoPermitido::class);
        $this->expectExceptionMessage('Você já pediu um backup nas últimas 24 horas. Tente de novo mais tarde.');
        $this->useCase->executar($this->usuario, $this->tenant);
    }

    #[TestDox('Se a fila falha, o pedido vira falhou (não fica preso na fila) e o erro sobe')]
    public function testFilaForaDoArMarcaFalhou(): void
    {
        $this->pedidos->method('temAbertoDaPessoa')->willReturn(false);
        $this->pedidos->method('quantosDaPessoaDesde')->willReturn(0);
        $salvos = [];
        $this->pedidos->method('salvar')->willReturnCallback(static function (PedidoDeBackup $p) use (&$salvos): void {
            $salvos[] = $p->getSituacao();
        });
        $this->bus->method('dispatch')->willThrowException(new \RuntimeException('fila fora'));

        try {
            $this->useCase->executar($this->usuario, $this->tenant);
            self::fail('Deveria relançar.');
        } catch (\RuntimeException) {
            self::assertSame([SituacaoDoBackup::Pedido, SituacaoDoBackup::Falhou], $salvos);
        }
    }
}
