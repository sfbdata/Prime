<?php

declare(strict_types=1);

namespace App\Tests\Auditoria\Unit;

use App\Auditoria\UseCase\DesfazerAlteracaoAuditLogUseCase;
use App\Entity\Audit\AuditLog;
use App\Repository\AuditLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(DesfazerAlteracaoAuditLogUseCase::class)]
final class DesfazerAlteracaoAuditLogUseCaseTest extends TestCase
{
    private MockObject $em;
    private MockObject $auditLogRepository;
    private DesfazerAlteracaoAuditLogUseCase $sut;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->auditLogRepository = $this->createMock(AuditLogRepository::class);
        $this->sut = new DesfazerAlteracaoAuditLogUseCase($this->em, $this->auditLogRepository);
    }

    private function criarLog(string $action, string $entityClass, ?string $entityId): AuditLog
    {
        $log = $this->createMock(AuditLog::class);
        $log->method('getAction')->willReturn($action);
        $log->method('getEntityClass')->willReturn($entityClass);
        $log->method('getEntityId')->willReturn($entityId);

        return $log;
    }

    public function testUpdatePastaComIdRetornaTrue(): void
    {
        $log = $this->criarLog('update', \App\Pasta\Entity\Pasta::class, '5');

        self::assertTrue($this->sut->podeReverter($log));
    }

    public function testUpdateTarefaComIdRetornaTrue(): void
    {
        $log = $this->criarLog('update', \App\Entity\Tarefa\Tarefa::class, '9');

        self::assertTrue($this->sut->podeReverter($log));
    }

    public function testUpdateUserRetornaFalsePorRiscoAlto(): void
    {
        $log = $this->criarLog('update', \App\Entity\Auth\User::class, '1');

        self::assertFalse($this->sut->podeReverter($log));
    }

    public function testUpdateTenantRetornaFalsePorRiscoAlto(): void
    {
        $log = $this->criarLog('update', \App\Entity\Tenant\Tenant::class, '1');

        self::assertFalse($this->sut->podeReverter($log));
    }

    public function testUpdateRegistroPontoRetornaFalsePorRiscoMedio(): void
    {
        $log = $this->criarLog('update', \App\Entity\Ponto\RegistroPonto::class, '1');

        self::assertFalse($this->sut->podeReverter($log));
    }

    public function testUpdatePastaObservacaoFinanceiraRetornaFalsePorRiscoMedio(): void
    {
        $log = $this->criarLog('update', \App\Pasta\Entity\PastaObservacaoFinanceira::class, '1');

        self::assertFalse($this->sut->podeReverter($log));
    }

    public function testCreatePastaRetornaFalse(): void
    {
        $log = $this->criarLog('create', \App\Pasta\Entity\Pasta::class, '5');

        self::assertFalse($this->sut->podeReverter($log));
    }

    public function testDeletePastaRetornaFalse(): void
    {
        $log = $this->criarLog('delete', \App\Pasta\Entity\Pasta::class, '5');

        self::assertFalse($this->sut->podeReverter($log));
    }

    public function testUpdatePastaSemEntityIdRetornaFalse(): void
    {
        $log = $this->criarLog('update', \App\Pasta\Entity\Pasta::class, null);

        self::assertFalse($this->sut->podeReverter($log));
    }
}
