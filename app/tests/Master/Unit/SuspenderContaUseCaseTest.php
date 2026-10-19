<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\DTO\SituacaoDaContaInput;
use App\Master\Exception\ContaNaoEncontrada;
use App\Master\Exception\OperacaoDeContaRecusada;
use App\Master\Repository\ContasDoSistemaRepository;
use App\Master\Service\RegraDosMasters;
use App\Master\UseCase\SuspenderContaUseCase;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Suspender conta pelo Master (spec perfis-1.2-contas C2, C5 e C6): caminho feliz e cada recusa.
 * Pedido de exclusão (C3/C4 com a frente 7d) ainda não existe: sem caso "com pedido pendente".
 */
#[CoversClass(SuspenderContaUseCase::class)]
final class SuspenderContaUseCaseTest extends TestCase
{
    private ContasDoSistemaRepository&MockObject $contas;
    private EscopoDeAuditoriaDePlataforma $escopo;
    private SuspenderContaUseCase $useCase;
    private User $master;

    protected function setUp(): void
    {
        $this->contas  = $this->createMock(ContasDoSistemaRepository::class);
        $this->escopo  = new EscopoDeAuditoriaDePlataforma();
        $this->useCase = new SuspenderContaUseCase($this->contas, new RegraDosMasters($this->contas), $this->escopo);
        $this->master  = self::usuario(['ROLE_SUPER_ADMIN']);
    }

    #[TestDox('suspende: isActive=false, excluidoEm continua nulo e grava no escopo da CONTA ALVO (C6)')]
    public function testSuspende(): void
    {
        $conta = self::conta(42, criador: self::usuario(['ROLE_USER']));
        $this->contas->method('buscar')->with(42)->willReturn($conta);
        $this->contas->expects($this->once())->method('salvar')->with($conta, true)
            ->willReturnCallback(fn () => self::assertSame(42, $this->escopo->contaAlvo(), 'a auditoria grava com o tenant da conta alvo'));

        $saida = $this->useCase->executar(new SituacaoDaContaInput($this->master, 42));

        self::assertFalse($conta->isActive());
        self::assertNull($conta->getExcluidoEm(), 'suspender não é excluir');
        self::assertSame('Suspensa', $saida->situacao);
        self::assertSame('Escritório 42', $saida->nome);
        self::assertFalse($this->escopo->estaLigado(), 'o escopo fecha depois do flush');
    }

    #[TestDox('sem criador registrado e sem Master vinculado: suspende')]
    public function testSuspendeContaSemCriadorESemMaster(): void
    {
        $conta = self::conta(42, criador: null);
        $this->contas->method('buscar')->willReturn($conta);
        $this->contas->method('existeMasterComVinculoAtivo')->with(42)->willReturn(false);
        $this->contas->expects($this->once())->method('salvar');

        $this->useCase->executar(new SituacaoDaContaInput($this->master, 42));

        self::assertFalse($conta->isActive());
    }

    #[TestDox('sem criador registrado, mas com Master de vínculo ativo: é conta do Master, recusa (fallback da C5)')]
    public function testRecusaContaSemCriadorComMasterVinculado(): void
    {
        $conta = self::conta(42, criador: null);
        $this->contas->method('buscar')->willReturn($conta);
        $this->contas->method('existeMasterComVinculoAtivo')->with(42)->willReturn(true);
        $this->esperarRecusa(RegraDosMasters::RECUSA_SUSPENDER_CONTA_DO_MASTER);

        try {
            $this->useCase->executar(new SituacaoDaContaInput($this->master, 42));
        } finally {
            self::assertTrue($conta->isActive(), 'nada muda');
        }
    }

    #[TestDox('quem não é Master é recusado antes de carregar a conta')]
    public function testRecusaQuemNaoEhMaster(): void
    {
        $this->contas->expects($this->never())->method('buscar');
        $this->esperarRecusa('Só o Master pode suspender ou reativar contas.');

        $this->useCase->executar(new SituacaoDaContaInput(self::usuario(['ROLE_USER']), 42));
    }

    #[TestDox('conta inexistente: ContaNaoEncontrada (o controller responde 404)')]
    public function testRecusaContaInexistente(): void
    {
        $this->contas->method('buscar')->willReturn(null);
        $this->contas->expects($this->never())->method('salvar');
        $this->expectException(ContaNaoEncontrada::class);
        $this->expectExceptionMessage('Conta não encontrada.');

        $this->useCase->executar(new SituacaoDaContaInput($this->master, 999));
    }

    #[TestDox('conta já suspensa é recusada')]
    public function testRecusaContaJaSuspensa(): void
    {
        $this->contas->method('buscar')->willReturn(self::conta(42, criador: null)->setIsActive(false));
        $this->esperarRecusa('Esta conta já está suspensa.');

        $this->useCase->executar(new SituacaoDaContaInput($this->master, 42));
    }

    #[TestDox('conta excluída (quarentena) é recusada, mesmo inativa')]
    public function testRecusaContaExcluida(): void
    {
        $this->contas->method('buscar')->willReturn(self::conta(42, criador: null)->setIsActive(false)->setExcluidoEm(new \DateTimeImmutable()));
        $this->esperarRecusa('Esta conta foi excluída e não pode ser suspensa.');

        $this->useCase->executar(new SituacaoDaContaInput($this->master, 42));
    }

    #[TestDox('conta criada por um Master é recusada (Q2): ele não tem bypass de conta suspensa')]
    public function testRecusaContaDoMaster(): void
    {
        $conta = self::conta(42, criador: self::usuario(['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO']));
        $this->contas->method('buscar')->willReturn($conta);
        $this->esperarRecusa(RegraDosMasters::RECUSA_SUSPENDER_CONTA_DO_MASTER);

        try {
            $this->useCase->executar(new SituacaoDaContaInput($this->master, 42));
        } finally {
            self::assertTrue($conta->isActive(), 'nada muda');
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
