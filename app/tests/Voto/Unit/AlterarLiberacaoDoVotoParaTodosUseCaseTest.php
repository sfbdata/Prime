<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Produto\Service\ContratacoesDoEscritorio;
use App\Repository\TenantRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Repository\LiberacaoDoVotoRepository;
use App\Voto\Service\GuardaDoMasterDoVoto;
use App\Voto\UseCase\AlterarLiberacaoDoVotoParaTodosUseCase;
use App\Voto\UseCase\AlterarLiberacaoDoVotoUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * BlueJus Voto (M5.9): o Master libera ou bloqueia o Voto de todos os escritórios ativos de uma vez. Mock só dos
 * repositórios (abstrações nossas); o escopo de auditoria é o objeto real.
 */
#[CoversClass(AlterarLiberacaoDoVotoParaTodosUseCase::class)]
#[CoversClass(GuardaDoMasterDoVoto::class)]
final class AlterarLiberacaoDoVotoParaTodosUseCaseTest extends TestCase
{
    private TenantRepository&MockObject $tenants;
    private LiberacaoDoVotoRepository&MockObject $liberacoes;
    private EscopoDeAuditoriaDePlataforma $escopo;
    private User $master;

    protected function setUp(): void
    {
        $this->tenants = $this->createMock(TenantRepository::class);
        $this->liberacoes = $this->createMock(LiberacaoDoVotoRepository::class);
        $this->escopo = new EscopoDeAuditoriaDePlataforma();
        $this->master = (new User())->setRoles(['ROLE_SUPER_ADMIN']);
    }

    private function sut(): AlterarLiberacaoDoVotoParaTodosUseCase
    {
        // A aba "para todos" passa pelo UseCase de um escritório (trava do escritório e trava do Master da D16).
        $contratacoes = $this->createMock(ContratacoesDoEscritorio::class);
        $contratacoes->method('sobTrava')->willReturnCallback(static fn (Tenant $t, callable $acao): mixed => $acao());
        $porEscritorio = new AlterarLiberacaoDoVotoUseCase($this->tenants, $this->liberacoes, $this->escopo, $contratacoes, new MockClock(new \DateTimeImmutable('2026-11-23 10:00:00')));

        return new AlterarLiberacaoDoVotoParaTodosUseCase($this->tenants, $this->liberacoes, $porEscritorio);
    }

    private function tenant(int $id): Tenant
    {
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, $id);

        return $tenant;
    }

    /** @param list<array{int, bool}> $linhas */
    private function grade(array $linhas): void
    {
        $this->liberacoes->method('listarEscritoriosParaOMaster')->willReturn(array_map(
            static fn (array $l): array => ['tenant_id' => $l[0], 'nome' => 'E' . $l[0], 'liberado' => $l[1]],
            $linhas,
        ));
    }

    #[TestDox('liberar todos: quem estava bloqueado ou sem linha muda, cada um grava no próprio escopo de auditoria; quem já estava liberado não é regravado')]
    public function testLiberaTodos(): void
    {
        $um = $this->tenant(1);
        $dois = $this->tenant(2);
        $tres = $this->tenant(3);
        $this->grade([[1, false], [2, false], [3, true]]);
        $this->tenants->method('find')->willReturnCallback(fn (mixed $id): ?Tenant => [1 => $um, 2 => $dois, 3 => $tres][$id] ?? null);
        $existente = new LiberacaoDoVoto($um);
        $this->liberacoes->method('findDoTenantParaOMaster')->willReturnCallback(fn (Tenant $t): ?LiberacaoDoVoto => $t === $um ? $existente : null);
        $gravadas = [];
        $this->liberacoes->expects($this->exactly(2))->method('salvar')->willReturnCallback(function (LiberacaoDoVoto $l, bool $flush) use (&$gravadas): void {
            self::assertTrue($flush);
            self::assertTrue($l->isLiberado());
            $gravadas[(int) $l->getTenant()->getId()] = $this->escopo->estaLigado() ? $this->escopo->contaAlvo() : null;
        });

        $alterados = $this->sut()->executar(true, $this->master);

        self::assertSame(2, $alterados);
        self::assertSame([1 => 1, 2 => 2], $gravadas, 'a auditoria cai em cada escritório alterado');
        self::assertFalse($this->escopo->estaLigado());
    }

    #[TestDox('bloquear todos: só quem estava liberado muda, e a linha existente é a mesma')]
    public function testBloqueiaTodos(): void
    {
        $um = $this->tenant(1);
        $this->grade([[1, true], [2, false]]);
        $this->tenants->method('find')->willReturnCallback(fn (mixed $id): ?Tenant => 1 === $id ? $um : null);
        $existente = new LiberacaoDoVoto($um);
        $existente->liberar();
        $this->liberacoes->method('findDoTenantParaOMaster')->with($um)->willReturn($existente);
        $this->liberacoes->expects($this->once())->method('salvar')->with($existente, true);

        self::assertSame(1, $this->sut()->executar(false, $this->master));
        self::assertFalse($existente->isLiberado());
    }

    #[TestDox('todos já no estado pedido: devolve 0 e não grava nada')]
    public function testNinguemMuda(): void
    {
        $this->grade([[1, true], [2, true]]);
        $this->tenants->expects($this->never())->method('find');
        $this->liberacoes->expects($this->never())->method('salvar');

        self::assertSame(0, $this->sut()->executar(true, $this->master));
    }

    #[TestDox('escritório que ficou suspenso, excluído ou sumiu entre a lista e a gravação não é tocado')]
    public function testInativoOuExcluidoNaoEntra(): void
    {
        $suspenso = $this->tenant(1)->setIsActive(false);
        $excluido = $this->tenant(2)->setIsActive(true)->setExcluidoEm(new \DateTimeImmutable());
        $this->grade([[1, false], [2, false], [3, false]]);
        $this->tenants->method('find')->willReturnCallback(fn (mixed $id): ?Tenant => [1 => $suspenso, 2 => $excluido][$id] ?? null);
        $this->liberacoes->expects($this->never())->method('salvar');

        self::assertSame(0, $this->sut()->executar(true, $this->master));
    }

    #[TestDox('quem não é Master: AccessDenied antes de listar qualquer escritório')]
    public function testNaoMaster(): void
    {
        $this->liberacoes->expects($this->never())->method('listarEscritoriosParaOMaster');
        $this->liberacoes->expects($this->never())->method('salvar');

        $this->expectException(AccessDeniedException::class);
        $this->sut()->executar(true, (new User())->setRoles(['ROLE_USER', 'ROLE_MASTER_PRIMARIO']));
    }
}
