<?php

declare(strict_types=1);

namespace App\Tests\Auditoria\Unit;

use App\Auditoria\UseCase\DesfazerAlteracaoAuditLogUseCase;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\MotivoDesativacaoChecklist;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PrioridadePasta;
use App\Repository\AuditLogRepository;
use App\Repository\UserTenantRepository;
use App\Shared\Doctrine\Filter\AcessoALixeira;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\FieldMapping;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(DesfazerAlteracaoAuditLogUseCase::class)]
final class DesfazerAlteracaoAuditLogUseCaseTest extends TestCase
{
    private MockObject $em;
    private MockObject $auditLogRepository;
    private MockObject $userTenantRepository;
    private DesfazerAlteracaoAuditLogUseCase $sut;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->auditLogRepository = $this->createMock(AuditLogRepository::class);
        $this->userTenantRepository = $this->createMock(UserTenantRepository::class);
        // `AcessoALixeira` com o EM dublado: nenhum filtro está ligado no FilterCollection dublado,
        // então o escopo só executa o trabalho — o que interessa aqui é a decisão do UseCase.
        $this->sut = new DesfazerAlteracaoAuditLogUseCase($this->em, $this->auditLogRepository, new AcessoALixeira($this->em), $this->userTenantRepository);
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

    #[TestDox('PrioridadePasta é um enum, não entidade: não está na lista de reversíveis')]
    public function testPrioridadePastaNaoEhReversivel(): void
    {
        $log = $this->criarLog('update', PrioridadePasta::class, '1');

        self::assertFalse($this->sut->podeReverter($log));
    }

    // ── D-AUDIT-UNDO: conversão pelo tipo da metadata, tudo ou nada ───────────

    /**
     * Atribui o id privado de uma entidade (sem setter de propósito).
     *
     * @template T of object
     *
     * @param T $entidade
     *
     * @return T
     */
    private function comId(object $entidade, int $id): object
    {
        (new \ReflectionProperty($entidade, 'id'))->setValue($entidade, $id);

        return $entidade;
    }

    private function tenant(int $id): Tenant
    {
        return $this->comId(new Tenant(), $id);
    }

    private function pastaDoTenant(Tenant $tenant, int $id = 5): Pasta
    {
        $pasta = $this->comId(new Pasta(), $id);
        $pasta->setNup('NUP-' . $id);
        $pasta->setTenant($tenant);

        return $pasta;
    }

    private function campo(string $nome, string $tipo, ?string $enum = null): FieldMapping
    {
        $mapping = new FieldMapping($tipo, $nome, $nome);
        $mapping->enumType = $enum;

        return $mapping;
    }

    /**
     * Metadata dublada só com o que o UseCase consulta.
     *
     * @param array<string, FieldMapping>              $campos
     * @param array<string, array{0: string, 1: bool}> $associacoes campo => [classe alvo, a-um?]
     */
    private function metadata(array $campos, array $associacoes = []): ClassMetadata
    {
        $meta = $this->createStub(ClassMetadata::class);
        $meta->method('hasField')->willReturnCallback(static fn (string $c): bool => isset($campos[$c]));
        $meta->method('getFieldMapping')->willReturnCallback(static fn (string $c): FieldMapping => $campos[$c]);
        $meta->method('hasAssociation')->willReturnCallback(static fn (string $c): bool => isset($associacoes[$c]));
        $meta->method('isSingleValuedAssociation')->willReturnCallback(static fn (string $c): bool => $associacoes[$c][1] ?? false);
        $meta->method('getAssociationTargetClass')->willReturnCallback(static fn (string $c): string => $associacoes[$c][0]);

        return $meta;
    }

    private function metadataDaPasta(): ClassMetadata
    {
        return $this->metadata(
            [
                'prioridade' => $this->campo('prioridade', Types::STRING, PrioridadePasta::class),
                'checklistMotivo' => $this->campo('checklistMotivo', Types::STRING, MotivoDesativacaoChecklist::class),
                'dataAbertura' => $this->campo('dataAbertura', Types::DATETIME_IMMUTABLE),
                'nomeAcao' => $this->campo('nomeAcao', Types::STRING),
            ],
            [
                'responsavel' => [User::class, true],
                'clientes' => [\App\Cliente\Entity\Cliente::class, false],
            ],
        );
    }

    /**
     * Log do tenant 1 sobre a pasta 5 com o diff dado; `find()` devolve a pasta e, para as outras
     * classes, o que estiver em $outros (classe => [id => objeto]).
     *
     * @param array<string, mixed>                       $diff
     * @param array<class-string, array<string, object>> $outros
     */
    private function cenarioDaPasta(Pasta $pasta, array $diff, array $outros = []): void
    {
        $log = $this->criarLog('update', Pasta::class, '5', $diff);
        $this->auditLogRepository->method('find')->with(10)->willReturn($log);
        $this->em->method('find')->willReturnCallback(
            static function (string $classe, mixed $id) use ($pasta, $outros): ?object {
                if ($classe === Pasta::class && (string) $id === '5') {
                    return $pasta;
                }

                return $outros[$classe][(string) $id] ?? null;
            },
        );
        $this->em->method('getClassMetadata')->willReturn($this->metadataDaPasta());
    }

    #[TestDox('enum no formato novo (->value) volta como o caso do enum, sem TypeError')]
    public function testEnumFormatoNovo(): void
    {
        $pasta = $this->pastaDoTenant($this->tenant(1));
        $pasta->setPrioridade(PrioridadePasta::Normal);
        $this->cenarioDaPasta($pasta, ['prioridade' => ['from' => 'urgente', 'to' => 'normal']]);
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->sut->executar(10, 1);

        self::assertTrue($resultado->sucesso);
        self::assertSame(PrioridadePasta::Urgente, $pasta->getPrioridade());
    }

    #[TestDox('enum no formato antigo {class, id: null, label: null}: não identifica o caso, recusa sem tocar em nada')]
    public function testEnumFormatoAntigoIrrecuperavelRecusa(): void
    {
        $pasta = $this->pastaDoTenant($this->tenant(1));
        $pasta->setPrioridade(PrioridadePasta::Normal);
        $this->cenarioDaPasta($pasta, [
            'nomeAcao' => ['from' => 'acao antiga', 'to' => 'ACAO NOVA'],
            'prioridade' => ['from' => ['class' => PrioridadePasta::class, 'id' => null, 'label' => null], 'to' => 'normal'],
        ]);
        $this->em->expects($this->never())->method('flush');

        $resultado = $this->sut->executar(10, 1);

        self::assertFalse($resultado->sucesso);
        self::assertSame(sprintf(DesfazerAlteracaoAuditLogUseCase::MENSAGEM_VALOR_IRRECUPERAVEL, 'prioridade'), $resultado->erro);
        self::assertSame(PrioridadePasta::Normal, $pasta->getPrioridade());
        self::assertNull($pasta->getNomeAcao(), 'o campo bom do mesmo diff também não pode ter sido aplicado');
    }

    #[TestDox('enum no formato antigo com label que identifica o caso: recupera')]
    public function testEnumFormatoAntigoComLabelRecupera(): void
    {
        $pasta = $this->pastaDoTenant($this->tenant(1));
        $this->cenarioDaPasta($pasta, [
            'prioridade' => ['from' => ['class' => PrioridadePasta::class, 'id' => null, 'label' => 'Urgente'], 'to' => 'normal'],
        ]);

        $resultado = $this->sut->executar(10, 1);

        self::assertTrue($resultado->sucesso);
        self::assertSame(PrioridadePasta::Urgente, $pasta->getPrioridade());
    }

    #[TestDox('enum com valor que não existe mais no enum: recusa')]
    public function testEnumValorDesconhecidoRecusa(): void
    {
        $pasta = $this->pastaDoTenant($this->tenant(1));
        $this->cenarioDaPasta($pasta, ['prioridade' => ['from' => 'altissima', 'to' => 'normal']]);
        $this->em->expects($this->never())->method('flush');

        $resultado = $this->sut->executar(10, 1);

        self::assertFalse($resultado->sucesso);
        self::assertSame(PrioridadePasta::Normal, $pasta->getPrioridade());
    }

    #[TestDox('data em ATOM volta como DateTimeImmutable no mesmo instante')]
    public function testDataAtomViraDateTimeImmutable(): void
    {
        $pasta = $this->pastaDoTenant($this->tenant(1));
        $this->cenarioDaPasta($pasta, ['dataAbertura' => ['from' => '2025-03-04T10:20:30-03:00', 'to' => '2026-10-06T08:00:00-03:00']]);
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->sut->executar(10, 1);

        self::assertTrue($resultado->sucesso);
        self::assertSame('2025-03-04T10:20:30-03:00', $pasta->getDataAbertura()->format(DATE_ATOM));
    }

    #[TestDox('data nula num setter que não aceita nulo: recusa em vez de TypeError')]
    public function testDataNulaEmSetterNaoNuloRecusa(): void
    {
        $pasta = $this->pastaDoTenant($this->tenant(1));
        $this->cenarioDaPasta($pasta, ['dataAbertura' => ['from' => null, 'to' => '2026-10-06T08:00:00-03:00']]);
        $this->em->expects($this->never())->method('flush');

        $resultado = $this->sut->executar(10, 1);

        self::assertFalse($resultado->sucesso);
        self::assertSame(sprintf(DesfazerAlteracaoAuditLogUseCase::MENSAGEM_VALOR_IRRECUPERAVEL, 'dataAbertura'), $resultado->erro);
    }

    #[TestDox('escalar: o valor anterior vai ao setter (que grava em maiúsculas)')]
    public function testEscalarFeliz(): void
    {
        $pasta = $this->pastaDoTenant($this->tenant(1));
        $pasta->setNomeAcao('ação nova');
        $this->cenarioDaPasta($pasta, ['nomeAcao' => ['from' => 'ação antiga', 'to' => 'AÇÃO NOVA']]);
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->sut->executar(10, 1);

        $esperado = (new Pasta())->setNomeAcao('ação antiga')->getNomeAcao();
        self::assertTrue($resultado->sucesso);
        self::assertSame($esperado, $pasta->getNomeAcao());
    }

    #[TestDox('campo sem setter (checklistMotivo) no diff: recusa a operação INTEIRA, nada muda, sem "sucesso"')]
    public function testCampoSemSetterRecusaTudo(): void
    {
        $pasta = $this->pastaDoTenant($this->tenant(1));
        $this->cenarioDaPasta($pasta, [
            'nomeAcao' => ['from' => 'acao antiga', 'to' => 'ACAO NOVA'],
            'checklistMotivo' => ['from' => 'encerrada', 'to' => null],
        ]);
        $this->em->expects($this->never())->method('flush');

        $resultado = $this->sut->executar(10, 1);

        self::assertFalse($resultado->sucesso);
        self::assertSame(sprintf(DesfazerAlteracaoAuditLogUseCase::MENSAGEM_CAMPO_NAO_REVERSIVEL, 'checklistMotivo'), $resultado->erro);
        self::assertNull($pasta->getNomeAcao());
        self::assertNull($pasta->getChecklistMotivo());
    }

    #[TestDox('item de coleção ([+:id]) não se desfaz por aqui: recusa em vez de no-op com sucesso')]
    public function testItemDeColecaoRecusa(): void
    {
        $pasta = $this->pastaDoTenant($this->tenant(1));
        $this->cenarioDaPasta($pasta, ['clientes[+:9]' => ['from' => null, 'to' => ['class' => 'Cliente', 'id' => '9', 'label' => 'X']]]);
        $this->em->expects($this->never())->method('flush');

        $resultado = $this->sut->executar(10, 1);

        self::assertFalse($resultado->sucesso);
        self::assertSame(sprintf(DesfazerAlteracaoAuditLogUseCase::MENSAGEM_CAMPO_NAO_REVERSIVEL, 'clientes[+:9]'), $resultado->erro);
    }

    #[TestDox('associação a usuário com vínculo no MESMO escritório: volta a referência')]
    public function testAssociacaoMesmoTenant(): void
    {
        $tenant = $this->tenant(1);
        $pasta = $this->pastaDoTenant($tenant);
        $antigo = $this->comId((new User())->setEmail('antigo@test.com'), 7);
        $this->cenarioDaPasta(
            $pasta,
            ['responsavel' => ['from' => ['class' => User::class, 'id' => '7', 'label' => 'Antigo'], 'to' => null]],
            [User::class => ['7' => $antigo], Tenant::class => ['1' => $tenant]],
        );
        $this->userTenantRepository->method('findPorUserETenant')->with($antigo, $tenant)->willReturn(new UserTenant($antigo, $tenant));
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->sut->executar(10, 1);

        self::assertTrue($resultado->sucesso);
        self::assertSame($antigo, $pasta->getResponsavel());
    }

    #[TestDox('associação a usuário SEM vínculo com o escritório: recusa, responsável não muda')]
    public function testAssociacaoUsuarioDeOutroTenantRecusa(): void
    {
        $tenant = $this->tenant(1);
        $pasta = $this->pastaDoTenant($tenant);
        $estranho = $this->comId((new User())->setEmail('estranho@test.com'), 8);
        $this->cenarioDaPasta(
            $pasta,
            ['responsavel' => ['from' => ['class' => User::class, 'id' => '8', 'label' => 'Estranho'], 'to' => null]],
            [User::class => ['8' => $estranho], Tenant::class => ['1' => $tenant]],
        );
        $this->userTenantRepository->method('findPorUserETenant')->willReturn(null);
        $this->em->expects($this->never())->method('flush');

        $resultado = $this->sut->executar(10, 1);

        self::assertFalse($resultado->sucesso);
        self::assertSame(sprintf(DesfazerAlteracaoAuditLogUseCase::MENSAGEM_ASSOCIACAO_INDISPONIVEL, 'responsavel'), $resultado->erro);
        self::assertNull($pasta->getResponsavel());
    }

    #[TestDox('associação a entidade TenantAware de OUTRO escritório (vinda do identity map): recusa')]
    public function testAssociacaoTenantAwareDeOutroTenantRecusa(): void
    {
        $tenant = $this->tenant(1);
        $minhaPasta = $this->pastaDoTenant($tenant, 5);
        $pastaAlheia = $this->pastaDoTenant($this->tenant(2), 99);
        $tarefa = $this->comId(new Tarefa(), 3);
        $tarefa->setTenant($tenant);
        $tarefa->setPasta($minhaPasta);

        $log = $this->criarLog('update', Tarefa::class, '3', ['pasta' => ['from' => ['class' => Pasta::class, 'id' => '99', 'label' => null], 'to' => ['class' => Pasta::class, 'id' => '5', 'label' => null]]]);
        $this->auditLogRepository->method('find')->willReturn($log);
        $this->em->method('find')->willReturnCallback(
            static fn (string $classe, mixed $id): ?object => match ([$classe, (string) $id]) {
                [Tarefa::class, '3'] => $tarefa,
                [Pasta::class, '99'] => $pastaAlheia,
                default => null,
            },
        );
        $this->em->method('getClassMetadata')->willReturn($this->metadata([], ['pasta' => [Pasta::class, true]]));
        $this->em->expects($this->never())->method('flush');

        $resultado = $this->sut->executar(10, 1);

        self::assertFalse($resultado->sucesso);
        self::assertSame(sprintf(DesfazerAlteracaoAuditLogUseCase::MENSAGEM_ASSOCIACAO_INDISPONIVEL, 'pasta'), $resultado->erro);
        self::assertSame($minhaPasta, $tarefa->getPasta());
    }

    #[TestDox('associação cujo alvo não existe mais: recusa (antes era pulada e dava "sucesso")')]
    public function testAssociacaoQueSumiuRecusa(): void
    {
        $pasta = $this->pastaDoTenant($this->tenant(1));
        $this->cenarioDaPasta($pasta, ['responsavel' => ['from' => ['class' => User::class, 'id' => '404', 'label' => null], 'to' => null]]);
        $this->em->expects($this->never())->method('flush');

        $resultado = $this->sut->executar(10, 1);

        self::assertFalse($resultado->sucesso);
        self::assertSame(sprintf(DesfazerAlteracaoAuditLogUseCase::MENSAGEM_ASSOCIACAO_INDISPONIVEL, 'responsavel'), $resultado->erro);
    }

    #[TestDox('entidade TenantAware de outro escritório (identity map): "Registro não encontrado", nada muda')]
    public function testEntidadeDeOutroTenantRecusa(): void
    {
        $pasta = $this->pastaDoTenant($this->tenant(2));
        $this->cenarioDaPasta($pasta, ['nomeAcao' => ['from' => 'x', 'to' => 'Y']]);
        $this->em->expects($this->never())->method('flush');

        $resultado = $this->sut->executar(10, 1);

        self::assertFalse($resultado->sucesso);
        self::assertSame('Registro não encontrado.', $resultado->erro);
        self::assertNull($pasta->getNomeAcao());
    }
}
