<?php

declare(strict_types=1);

namespace App\Tests\Auditoria\Unit;

use App\Auditoria\Service\EstadoDaTelaNoDesfazer;
use App\Auditoria\UseCase\DesfazerAlteracaoAuditLogUseCase;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Service\ComporNomeDaPastaJudicial;
use App\Cobranca\Service\ResolvedorIdentificadorDaPasta;
use App\Entity\Audit\AuditLog;
use App\Pasta\UseCase\RenomearClienteDaPastaUseCase;
use App\Ponto\Entity\PontoAjuste;
use App\Ponto\Entity\RegistroPonto;
use App\Repository\AuditLogRepository;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;
use App\Shared\Contract\Auditavel;
use App\Shared\Doctrine\Filter\AcessoALixeira;
use App\Tarefa\Service\TravaDaMeta;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * E10 (plano-e10-ponto §2): a batida gravada só entra por inserção e a correção é um ajuste novo. O "Desfazer" do
 * audit_log não pode ser um atalho para mudar uma batida (`RegistroPonto`) nem um ajuste (`PontoAjuste`): nenhum dos
 * dois é reversível, em ação nenhuma, e o ajuste nem é auditado pelo audit_log (o evento vai para a trilha do Ponto).
 */
#[CoversClass(DesfazerAlteracaoAuditLogUseCase::class)]
final class DesfazerNuncaTocaNaBatidaDoPontoTest extends TestCase
{
    private MockObject&EntityManagerInterface $em;
    private MockObject&AuditLogRepository $auditLogRepository;
    private DesfazerAlteracaoAuditLogUseCase $sut;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->auditLogRepository = $this->createMock(AuditLogRepository::class);
        $this->em->method('wrapInTransaction')->willReturnCallback(fn (\Closure $trabalho): mixed => $trabalho($this->em));
        $estado = new EstadoDaTelaNoDesfazer(
            $this->em,
            $this->createStub(TranslatorInterface::class),
            new RenomearClienteDaPastaUseCase(
                $this->createStub(PermissionChecker::class),
                $this->em,
                new ResolvedorIdentificadorDaPasta($this->createStub(CasoCobrancaRepository::class), new ComporNomeDaPastaJudicial()),
            ),
        );
        $this->sut = new DesfazerAlteracaoAuditLogUseCase(
            $this->em,
            $this->auditLogRepository,
            new AcessoALixeira($this->em),
            $this->createStub(UserTenantRepository::class),
            $estado,
            $this->createStub(TravaDaMeta::class),
        );
    }

    /** @return iterable<string, array{class-string, string}> */
    public static function linhasDoPonto(): iterable
    {
        foreach (['update', 'create', 'delete'] as $acao) {
            yield 'RegistroPonto ' . $acao => [RegistroPonto::class, $acao];
            yield 'PontoAjuste ' . $acao => [PontoAjuste::class, $acao];
        }
    }

    #[DataProvider('linhasDoPonto')]
    #[TestDox('$_dataName: o audit_log nunca desfaz (podeReverter falso)')]
    public function testNuncaReversivel(string $classe, string $acao): void
    {
        self::assertFalse($this->sut->podeReverter($this->log($acao, $classe)));
    }

    #[DataProvider('linhasDoPonto')]
    #[TestDox('$_dataName: executar recusa sem ler nem gravar nada da entidade')]
    public function testExecutarRecusaSemTocarNaEntidade(string $classe, string $acao): void
    {
        $log = $this->log($acao, $classe);
        $this->auditLogRepository->method('find')->with(7)->willReturn($log);
        $this->em->expects(self::never())->method('find');
        $this->em->expects(self::never())->method('flush');

        $resultado = $this->sut->executar(7, 1);

        self::assertFalse($resultado->sucesso);
        self::assertSame('Esta alteração não pode ser desfeita automaticamente.', $resultado->erro);
    }

    #[TestDox('A lista das entidades reversíveis não tem nenhuma classe do Ponto, e o ajuste não é Auditavel')]
    public function testNenhumaEntidadeDoPontoNaLista(): void
    {
        $reversiveis = (new \ReflectionClassConstant(DesfazerAlteracaoAuditLogUseCase::class, 'ENTIDADES_REVERSIVEIS'))->getValue();

        self::assertIsArray($reversiveis);
        foreach ($reversiveis as $classe) {
            self::assertStringStartsNotWith('App\\Ponto\\', $classe);
        }
        self::assertFalse(is_subclass_of(PontoAjuste::class, Auditavel::class), 'o ajuste vai para a trilha do Ponto, não para o audit_log');
    }

    private function log(string $acao, string $classe): AuditLog
    {
        $log = $this->createStub(AuditLog::class);
        $log->method('getAction')->willReturn($acao);
        $log->method('getEntityClass')->willReturn($classe);
        $log->method('getEntityId')->willReturn('42');
        $log->method('getTenantId')->willReturn(1);
        $log->method('getChanges')->willReturn(['diff' => ['changes' => ['dataHora' => ['from' => '2026-10-21T08:00:00-03:00', 'to' => '2026-10-21T09:00:00-03:00']]]]);

        return $log;
    }
}
