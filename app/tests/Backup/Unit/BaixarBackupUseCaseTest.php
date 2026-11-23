<?php

declare(strict_types=1);

namespace App\Tests\Backup\Unit;

use App\Backup\DTO\ResultadoDoBackup;
use App\Backup\Entity\PedidoDeBackup;
use App\Backup\Exception\BackupIndisponivel;
use App\Backup\Repository\PedidoDeBackupRepository;
use App\Backup\Service\DonoDoEscritorio;
use App\Backup\UseCase\BaixarBackupUseCase;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(BaixarBackupUseCase::class)]
final class BaixarBackupUseCaseTest extends TestCase
{
    private const SHA = 'a3f1c2d4e5b60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
    private const AGORA = '2026-11-23 12:00:00';

    private PedidoDeBackupRepository&MockObject $pedidos;
    private ArmazenamentoDeArquivos&MockObject $armazenamento;
    private EntityManagerInterface&MockObject $em;
    private BaixarBackupUseCase $useCase;
    private User $dono;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->pedidos = $this->createMock(PedidoDeBackupRepository::class);
        $this->armazenamento = $this->createMock(ArmazenamentoDeArquivos::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new BaixarBackupUseCase($this->pedidos, $this->armazenamento, $this->em, new MockClock(self::AGORA), new DonoDoEscritorio());
        $this->dono = self::usuario(7);
        $this->tenant = self::tenant(3);
        $this->tenant->setCriadoPor($this->dono);
    }

    private static function usuario(int $id): User
    {
        $u = (new User())->setEmail("u{$id}@teste.com");
        (new \ReflectionProperty(User::class, 'id'))->setValue($u, $id);

        return $u;
    }

    private static function tenant(int $id): Tenant
    {
        $t = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($t, $id);

        return $t;
    }

    private function pronto(string $expira = '2026-11-30 12:00:00'): PedidoDeBackup
    {
        $pedido = new PedidoDeBackup($this->tenant, $this->dono, new \DateTimeImmutable('2026-11-23 11:00:00'));
        (new \ReflectionProperty(PedidoDeBackup::class, 'id'))->setValue($pedido, 55);
        $pedido->iniciarMontagem(new \DateTimeImmutable('2026-11-23 11:01:00'));
        $pedido->concluir(new ResultadoDoBackup('abc.zip', 2048, self::SHA, 1, 0), new \DateTimeImmutable('2026-11-23 11:30:00'), new \DateTimeImmutable($expira));

        return $pedido;
    }

    #[TestDox('Dono, pronto e na validade: devolve a chave e o nome, sem gravar auditoria ainda')]
    public function testDonoBaixa(): void
    {
        $this->pedidos->expects(self::once())->method('doEscritorio')->with(55, 3)->willReturn($this->pronto());
        $this->armazenamento->method('existe')->willReturn(true);
        $this->em->expects(self::never())->method('persist');

        $saida = $this->useCase->executar(55, $this->dono, $this->tenant);

        self::assertSame('backup-bluejus-2026-11-23.zip', $saida->nomeDoArquivo);
        self::assertSame('abc.zip', $saida->chave->nome);
        self::assertSame(55, $saida->pedidoId);
    }

    #[TestDox('registrarDownload grava backup_dl no audit_log com pedido, escritório e pessoa')]
    public function testRegistrarDownload(): void
    {
        $this->pedidos->method('doEscritorio')->willReturn($this->pronto());
        $this->armazenamento->method('existe')->willReturn(true);
        $saida = $this->useCase->executar(55, $this->dono, $this->tenant);
        $this->em->expects(self::once())->method('persist')->with(self::callback(static function (AuditLog $log): bool {
            return 'backup_dl' === $log->getAction() && '55' === $log->getEntityId() && 3 === $log->getTenantId() && 7 === $log->getActorUserId();
        }));
        $this->em->expects(self::once())->method('flush');

        $this->useCase->registrarDownload($saida, $this->dono, $this->tenant);
    }

    #[TestDox('Pedido que não é do escritório atual (repositório devolve nulo): indisponível, sem auditoria')]
    public function testOutroEscritorio(): void
    {
        $this->pedidos->method('doEscritorio')->willReturn(null);
        $this->em->expects(self::never())->method('persist');

        $this->expectException(BackupIndisponivel::class);
        $this->useCase->executar(55, $this->dono, $this->tenant);
    }

    #[TestDox('Quem não é o dono (inclusive de pedido antigo dele): acesso negado antes de tudo, sem auditoria')]
    public function testOutroUsuario(): void
    {
        $this->pedidos->expects(self::never())->method('doEscritorio');
        $this->armazenamento->expects(self::never())->method('existe');
        $this->em->expects(self::never())->method('persist');

        $this->expectException(AccessDeniedException::class);
        $this->useCase->executar(55, self::usuario(8), $this->tenant);
    }

    #[TestDox('Vencido pela data: indisponível')]
    public function testVencido(): void
    {
        $this->pedidos->method('doEscritorio')->willReturn($this->pronto('2026-11-23 12:00:00'));
        $this->em->expects(self::never())->method('persist');

        $this->expectException(BackupIndisponivel::class);
        $this->useCase->executar(55, $this->dono, $this->tenant);
    }

    #[TestDox('Não pronto: indisponível')]
    public function testNaoPronto(): void
    {
        $pedido = new PedidoDeBackup($this->tenant, $this->dono, new \DateTimeImmutable('2026-11-23 11:00:00'));
        $this->pedidos->method('doEscritorio')->willReturn($pedido);

        $this->expectException(BackupIndisponivel::class);
        $this->useCase->executar(55, $this->dono, $this->tenant);
    }

    #[TestDox('Arquivo sumiu do armazenamento: indisponível, sem auditoria')]
    public function testArquivoSumiu(): void
    {
        $this->pedidos->method('doEscritorio')->willReturn($this->pronto());
        $this->armazenamento->method('existe')->willReturn(false);
        $this->em->expects(self::never())->method('persist');

        $this->expectException(BackupIndisponivel::class);
        $this->useCase->executar(55, $this->dono, $this->tenant);
    }
}
