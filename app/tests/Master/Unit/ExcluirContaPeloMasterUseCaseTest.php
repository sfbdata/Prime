<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\DTO\ExcluirContaInput;
use App\Master\Exception\ContaNaoEncontrada;
use App\Master\Exception\OperacaoDeContaRecusada;
use App\Master\Repository\ContasDoSistemaRepository;
use App\Master\Service\RegraDosMasters;
use App\Master\UseCase\ExcluirContaPeloMasterUseCase;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Tenant\Repository\PedidoDeExclusaoDeContaRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Excluir conta pelo Master (spec perfis-1.2-contas C4, C5 e C6; Q1: quarentena de 365 dias): caminho
 * feliz com a conta ativa e com a conta SUSPENSA, e cada recusa. Pedido de exclusão da 7d ainda não existe.
 */
#[CoversClass(ExcluirContaPeloMasterUseCase::class)]
final class ExcluirContaPeloMasterUseCaseTest extends TestCase
{
    private const AGORA = '2026-10-19 10:00:00';

    private ContasDoSistemaRepository&MockObject $contas;
    private EscopoDeAuditoriaDePlataforma $escopo;
    private ExcluirContaPeloMasterUseCase $useCase;
    private User $master;

    protected function setUp(): void
    {
        $this->contas  = $this->createMock(ContasDoSistemaRepository::class);
        $this->escopo  = new EscopoDeAuditoriaDePlataforma();
        $this->useCase = new ExcluirContaPeloMasterUseCase($this->contas, new RegraDosMasters($this->contas), $this->escopo, new MockClock(self::AGORA), $this->createStub(PedidoDeExclusaoDeContaRepository::class), 365);
        $this->master  = self::usuario(['ROLE_SUPER_ADMIN']);
    }

    #[TestDox('exclui a conta ativa: isActive=false, excluidoEm=agora, escopo da conta alvo e purga prevista em 365 dias')]
    public function testExcluiContaAtiva(): void
    {
        $conta = self::conta(42, criador: self::usuario(['ROLE_USER']));
        $this->contas->method('buscar')->with(42)->willReturn($conta);
        $this->contas->expects($this->once())->method('salvar')->with($conta, true)
            ->willReturnCallback(fn () => self::assertSame(42, $this->escopo->contaAlvo(), 'a auditoria grava com o tenant da conta alvo'));

        $saida = $this->useCase->executar(new ExcluirContaInput($this->master, 42, 'EXCLUIR'));

        self::assertFalse($conta->isActive());
        self::assertSame(self::AGORA, $conta->getExcluidoEm()?->format('Y-m-d H:i:s'));
        self::assertSame('Em quarentena', $saida->situacao);
        self::assertSame('2027-10-19', $saida->purgaEm);
        self::assertFalse($this->escopo->estaLigado());
    }

    #[TestDox('exclui a conta SUSPENSA também (o ExcluirEscritorioUseCase antigo saía cedo nesse caso)')]
    public function testExcluiContaSuspensa(): void
    {
        $conta = self::conta(42, criador: null)->setIsActive(false);
        $this->contas->method('buscar')->willReturn($conta);
        $this->contas->method('existeMasterComVinculoAtivo')->willReturn(false);
        $this->contas->expects($this->once())->method('salvar');

        $this->useCase->executar(new ExcluirContaInput($this->master, 42, ' EXCLUIR '));

        self::assertFalse($conta->isActive());
        self::assertNotNull($conta->getExcluidoEm());
    }

    #[TestDox('quem não é Master é recusado antes de carregar a conta')]
    public function testRecusaQuemNaoEhMaster(): void
    {
        $this->contas->expects($this->never())->method('buscar');
        $this->esperarRecusa('Só o Master pode excluir contas.');

        $this->useCase->executar(new ExcluirContaInput(self::usuario(['ROLE_USER']), 42, 'EXCLUIR'));
    }

    #[TestDox('conta inexistente: ContaNaoEncontrada (o controller responde 404)')]
    public function testContaInexistente(): void
    {
        $this->contas->method('buscar')->willReturn(null);
        $this->contas->expects($this->never())->method('salvar');
        $this->expectException(ContaNaoEncontrada::class);

        $this->useCase->executar(new ExcluirContaInput($this->master, 999, 'EXCLUIR'));
    }

    #[TestDox('sem a palavra EXCLUIR (vazia, minúscula ou outra) é recusado no servidor e nada muda')]
    public function testRecusaSemPalavra(): void
    {
        foreach (['', 'excluir', 'EXCLUI', 'sim'] as $digitado) {
            $conta = self::conta(42, criador: null);
            $contas = $this->createMock(ContasDoSistemaRepository::class);
            $contas->method('buscar')->willReturn($conta);
            $contas->expects($this->never())->method('salvar');
            $useCase = new ExcluirContaPeloMasterUseCase($contas, new RegraDosMasters($contas), $this->escopo, new MockClock(self::AGORA), $this->createStub(PedidoDeExclusaoDeContaRepository::class), 365);

            try {
                $useCase->executar(new ExcluirContaInput($this->master, 42, $digitado));
                self::fail('deveria recusar: "' . $digitado . '"');
            } catch (OperacaoDeContaRecusada $e) {
                self::assertSame('Digite EXCLUIR para confirmar a exclusão.', $e->getMessage());
            }
            self::assertTrue($conta->isActive());
            self::assertNull($conta->getExcluidoEm());
        }
    }

    #[TestDox('conta já excluída é recusada')]
    public function testRecusaContaJaExcluida(): void
    {
        $this->contas->method('buscar')->willReturn(self::conta(42, criador: null)->setIsActive(false)->setExcluidoEm(new \DateTimeImmutable('-3 days')));
        $this->esperarRecusa('Esta conta já foi excluída.');

        $this->useCase->executar(new ExcluirContaInput($this->master, 42, 'EXCLUIR'));
    }

    #[TestDox('conta do Master é recusada (C5): pelo criador e, sem criador, pelo Master com vínculo ativo')]
    public function testRecusaContaDoMaster(): void
    {
        $this->contas->method('buscar')->willReturnOnConsecutiveCalls(
            self::conta(42, criador: self::usuario(['ROLE_SUPER_ADMIN'])),
            self::conta(43, criador: null),
        );
        $this->contas->method('existeMasterComVinculoAtivo')->with(43)->willReturn(true);
        $this->contas->expects($this->never())->method('salvar');

        foreach ([42, 43] as $id) {
            try {
                $this->useCase->executar(new ExcluirContaInput($this->master, $id, 'EXCLUIR'));
                self::fail('deveria recusar a conta ' . $id);
            } catch (OperacaoDeContaRecusada $e) {
                self::assertSame(RegraDosMasters::RECUSA_EXCLUIR_CONTA_DO_MASTER, $e->getMessage());
            }
        }
    }

    private function esperarRecusa(string $mensagem): void
    {
        $this->contas->expects($this->never())->method('salvar');
        $this->expectException(OperacaoDeContaRecusada::class);
        $this->expectExceptionMessage($mensagem);
    }

    /** @param list<string> $papeis */
    private static function usuario(array $papeis): User
    {
        return (new User())->setEmail(uniqid() . '@teste.local')->setFullName('Pessoa')->setRoles($papeis);
    }

    private static function conta(int $id, ?User $criador): Tenant
    {
        $conta = (new Tenant())->setName('Escritório ' . $id)->setCriadoPor($criador);
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($conta, $id);

        return $conta;
    }
}
