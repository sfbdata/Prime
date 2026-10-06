<?php

declare(strict_types=1);

namespace App\Tests\Auditoria\Unit;

use App\Auditoria\UseCase\DesfazerAlteracaoAuditLogUseCase;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Pasta\Entity\PastaDocumento;
use App\Repository\AuditLogRepository;
use App\Shared\Doctrine\Filter\AcessoALixeira;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
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
        // `AcessoALixeira` com o EM dublado: nenhum filtro está ligado no FilterCollection dublado,
        // então o escopo só executa o trabalho — o que interessa aqui é a decisão do UseCase.
        $this->sut = new DesfazerAlteracaoAuditLogUseCase($this->em, $this->auditLogRepository, new AcessoALixeira($this->em));
    }

    /** @param array<string, mixed>|null $diff o `changes` do diff (campo => {from, to}) */
    private function criarLog(string $action, string $entityClass, ?string $entityId, ?array $diff = null, int $tenantId = 1): AuditLog
    {
        $log = $this->createMock(AuditLog::class);
        $log->method('getAction')->willReturn($action);
        $log->method('getEntityClass')->willReturn($entityClass);
        $log->method('getEntityId')->willReturn($entityId);
        $log->method('getTenantId')->willReturn($tenantId);
        $log->method('getChanges')->willReturn($diff === null ? null : ['diff' => ['changes' => $diff]]);

        return $log;
    }

    // ── Lixeira (D7) ───────────────────────────────────────────────────────────

    #[TestDox('item na lixeira: o find() filtrado não o acha, mas a mensagem diz que ele está na lixeira (restaure antes), não "não existe mais"')]
    public function testItemNaLixeiraPedeRestaurarAntes(): void
    {
        $log = $this->criarLog('update', PastaDocumento::class, '5', ['nomeOriginal' => ['from' => 'a.pdf', 'to' => 'b.pdf']]);
        $this->auditLogRepository->method('find')->with(10)->willReturn($log);

        $naLixeira = (new PastaDocumento())->marcarExcluido((new User())->setEmail('x@test.com'), new \DateTimeImmutable('2026-10-07 10:00:00'));
        // 1ª chamada: com o LixeiraFilter (null); 2ª: dentro da AcessoALixeira (acha, na lixeira).
        $this->em->method('find')->with(PastaDocumento::class, '5')->willReturnOnConsecutiveCalls(null, $naLixeira);
        $this->em->expects($this->never())->method('flush');

        $resultado = $this->sut->executar(10, 1);

        self::assertFalse($resultado->sucesso);
        self::assertSame(DesfazerAlteracaoAuditLogUseCase::MENSAGEM_ITEM_NA_LIXEIRA, $resultado->erro);
    }

    #[TestDox('item que sumiu de verdade continua "Entidade não existe mais"')]
    public function testItemQueSumiuDeVerdade(): void
    {
        $log = $this->criarLog('update', PastaDocumento::class, '5', ['nomeOriginal' => ['from' => 'a.pdf', 'to' => 'b.pdf']]);
        $this->auditLogRepository->method('find')->willReturn($log);
        $this->em->method('find')->willReturn(null);

        $resultado = $this->sut->executar(10, 1);

        self::assertFalse($resultado->sucesso);
        self::assertSame('Entidade não existe mais.', $resultado->erro);
    }

    #[TestDox('a ida/volta da lixeira (diff em excluidoEm) não se desfaz por aqui: aponta para a lixeira da pasta, sem tocar no banco')]
    public function testAlteracaoDaLixeiraNaoSeDesfaz(): void
    {
        $log = $this->criarLog('update', PastaDocumento::class, '5', ['excluidoEm' => ['from' => null, 'to' => '2026-10-07T10:00:00+00:00'], 'excluidoPor' => ['from' => null, 'to' => ['class' => 'User', 'id' => 7]]]);
        $this->auditLogRepository->method('find')->willReturn($log);
        $this->em->expects($this->never())->method('find');
        $this->em->expects($this->never())->method('flush');

        $resultado = $this->sut->executar(10, 1);

        self::assertFalse($resultado->sucesso);
        self::assertSame(DesfazerAlteracaoAuditLogUseCase::MENSAGEM_ALTERACAO_DA_LIXEIRA, $resultado->erro);
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
        $log = $this->criarLog('update', \App\Ponto\Entity\RegistroPonto::class, '1');

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
