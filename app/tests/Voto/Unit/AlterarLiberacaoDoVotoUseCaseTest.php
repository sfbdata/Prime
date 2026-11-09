<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\TenantRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Exception\EscritorioNaoEncontradoException;
use App\Voto\Repository\LiberacaoDoVotoRepository;
use App\Voto\Service\GuardaDoMasterDoVoto;
use App\Voto\UseCase\AlterarLiberacaoDoVotoUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * BlueJus Voto, fase A1b (docs/specs/bluejus-voto.md, D5 e D7): o Master libera ou bloqueia o Voto de um escritório.
 * Mock só do repositório do Voto e do de escritórios (abstrações nossas); o escopo de auditoria é o objeto real.
 */
#[CoversClass(AlterarLiberacaoDoVotoUseCase::class)]
#[CoversClass(LiberacaoDoVoto::class)]
#[CoversClass(GuardaDoMasterDoVoto::class)]
#[CoversClass(EscritorioNaoEncontradoException::class)]
final class AlterarLiberacaoDoVotoUseCaseTest extends TestCase
{
    private const ID_DO_ESCRITORIO = 7;

    private TenantRepository&MockObject $tenants;
    private LiberacaoDoVotoRepository&MockObject $liberacoes;
    private EscopoDeAuditoriaDePlataforma $escopo;
    private Tenant $tenant;
    private User $master;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($this->tenant, self::ID_DO_ESCRITORIO);
        $this->tenants = $this->createMock(TenantRepository::class);
        $this->tenants->method('find')->willReturnCallback(fn (mixed $id): ?Tenant => $id === self::ID_DO_ESCRITORIO ? $this->tenant : null);
        $this->liberacoes = $this->createMock(LiberacaoDoVotoRepository::class);
        $this->escopo = new EscopoDeAuditoriaDePlataforma();
        $this->master = (new User())->setRoles(['ROLE_SUPER_ADMIN']);
    }

    private function sut(): AlterarLiberacaoDoVotoUseCase
    {
        return new AlterarLiberacaoDoVotoUseCase($this->tenants, $this->liberacoes, $this->escopo);
    }

    #[TestDox('a liberação nasce não liberada, com a data de criação e a de atualização iguais')]
    public function testLiberacaoNasceBloqueada(): void
    {
        $liberacao = new LiberacaoDoVoto($this->tenant);

        self::assertFalse($liberacao->isLiberado());
        self::assertSame($this->tenant, $liberacao->getTenant());
        self::assertEquals($liberacao->getCriadaEm(), $liberacao->getAtualizadaEm());
        $liberacao->liberar();
        self::assertTrue($liberacao->isLiberado());
        $liberacao->bloquear();
        self::assertFalse($liberacao->isLiberado());
    }

    #[TestDox('escritório sem linha: a liberação nasce aqui, liberada, e é gravada no escopo de auditoria do escritório alterado')]
    public function testCriaALinhaLiberada(): void
    {
        $this->liberacoes->method('findDoTenantParaOMaster')->with($this->tenant)->willReturn(null);
        $salva = null;
        $contaDoEscopo = null;
        $this->liberacoes->expects($this->once())->method('salvar')->willReturnCallback(function (LiberacaoDoVoto $l, bool $flush) use (&$salva, &$contaDoEscopo): void {
            self::assertTrue($flush);
            $salva = $l;
            $contaDoEscopo = $this->escopo->estaLigado() ? $this->escopo->contaAlvo() : 'fora do escopo';
        });

        $this->sut()->executar(self::ID_DO_ESCRITORIO, true, $this->master);

        self::assertInstanceOf(LiberacaoDoVoto::class, $salva);
        self::assertSame($this->tenant, $salva->getTenant());
        self::assertTrue($salva->isLiberado());
        self::assertSame(self::ID_DO_ESCRITORIO, $contaDoEscopo, 'o audit_log cai no escritório alterado, não no da sessão do Master');
        self::assertFalse($this->escopo->estaLigado(), 'o escopo fecha depois de gravar');
    }

    #[TestDox('linha existente liberada: bloquear grava a mesma linha, sem criar outra')]
    public function testBloqueiaALinhaExistente(): void
    {
        $existente = new LiberacaoDoVoto($this->tenant);
        $existente->liberar();
        $this->liberacoes->method('findDoTenantParaOMaster')->with($this->tenant)->willReturn($existente);
        $this->liberacoes->expects($this->once())->method('salvar')->with($existente, true);

        $this->sut()->executar(self::ID_DO_ESCRITORIO, false, $this->master);

        self::assertFalse($existente->isLiberado());
    }

    #[TestDox('escritório inexistente, com id inválido, suspenso ou excluído: EscritorioNaoEncontrado e nada é gravado')]
    public function testEscritorioInvalido(): void
    {
        $this->liberacoes->expects($this->never())->method('salvar');
        $casos = [
            [99, static fn (): null => null],
            [0, static fn (): null => null],
            [self::ID_DO_ESCRITORIO, fn () => $this->tenant->setIsActive(false)],
            [self::ID_DO_ESCRITORIO, fn () => $this->tenant->setIsActive(true)->setExcluidoEm(new \DateTimeImmutable())],
        ];
        foreach ($casos as [$id, $preparar]) {
            $preparar();
            try {
                $this->sut()->executar($id, true, $this->master);
                self::fail('deveria recusar o escritório ' . $id);
            } catch (EscritorioNaoEncontradoException $e) {
                self::assertSame($id, $e->tenantId);
                self::assertSame('Escritório não encontrado.', $e->getMessage());
            }
        }
    }

    #[TestDox('quem não é Master: AccessDenied antes de procurar o escritório')]
    public function testNaoMaster(): void
    {
        $this->tenants->expects($this->never())->method('find');
        $this->liberacoes->expects($this->never())->method('salvar');

        $this->expectException(AccessDeniedException::class);
        $this->sut()->executar(self::ID_DO_ESCRITORIO, true, (new User())->setRoles(['ROLE_USER', 'ROLE_MASTER_PRIMARIO']));
    }
}
