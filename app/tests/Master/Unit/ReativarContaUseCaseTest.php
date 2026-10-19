<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\DTO\SituacaoDaContaInput;
use App\Master\Exception\OperacaoDeContaRecusada;
use App\Master\Repository\ContasDoSistemaRepository;
use App\Master\Service\RegraDosMasters;
use App\Master\UseCase\ReativarContaUseCase;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Reativar conta (spec perfis-1.2-contas C3 e C6): caminho feliz e cada recusa. O caso "com pedido
 * de exclusão pendente" chega com a entidade da frente 7d (ponto de extensão no UseCase).
 */
#[CoversClass(ReativarContaUseCase::class)]
final class ReativarContaUseCaseTest extends TestCase
{
    private ContasDoSistemaRepository&MockObject $contas;
    private EscopoDeAuditoriaDePlataforma $escopo;
    private ReativarContaUseCase $useCase;
    private User $master;

    protected function setUp(): void
    {
        $this->contas  = $this->createMock(ContasDoSistemaRepository::class);
        $this->escopo  = new EscopoDeAuditoriaDePlataforma();
        $this->useCase = new ReativarContaUseCase($this->contas, new RegraDosMasters(), $this->escopo);
        $this->master  = self::usuario(['ROLE_SUPER_ADMIN']);
    }

    #[TestDox('reativa a conta suspensa: isActive=true e grava no escopo da CONTA ALVO (C6)')]
    public function testReativa(): void
    {
        $conta = self::conta(42)->setIsActive(false);
        $this->contas->method('buscar')->with(42)->willReturn($conta);
        $this->contas->expects($this->once())->method('salvar')->with($conta, true)
            ->willReturnCallback(fn () => self::assertSame(42, $this->escopo->contaAlvo()));

        $saida = $this->useCase->executar(new SituacaoDaContaInput($this->master, 42));

        self::assertTrue($conta->isActive());
        self::assertSame('Ativa', $saida->situacao);
        self::assertFalse($this->escopo->estaLigado());
    }

    #[TestDox('a conta do Master suspensa (estado anterior à regra) pode ser reativada')]
    public function testReativaContaDoMaster(): void
    {
        $conta = self::conta(42)->setIsActive(false)->setCriadoPor(self::usuario(['ROLE_SUPER_ADMIN']));
        $this->contas->method('buscar')->willReturn($conta);
        $this->contas->expects($this->once())->method('salvar');

        $this->useCase->executar(new SituacaoDaContaInput($this->master, 42));

        self::assertTrue($conta->isActive());
    }

    #[TestDox('quem não é Master é recusado')]
    public function testRecusaQuemNaoEhMaster(): void
    {
        $this->contas->expects($this->never())->method('buscar');
        $this->esperarRecusa('Só o Master pode suspender ou reativar contas.');

        $this->useCase->executar(new SituacaoDaContaInput(self::usuario(['ROLE_USER']), 42));
    }

    #[TestDox('conta inexistente é recusada')]
    public function testRecusaContaInexistente(): void
    {
        $this->contas->method('buscar')->willReturn(null);
        $this->esperarRecusa('Conta não encontrada.');

        $this->useCase->executar(new SituacaoDaContaInput($this->master, 999));
    }

    #[TestDox('conta já ativa é recusada')]
    public function testRecusaContaAtiva(): void
    {
        $this->contas->method('buscar')->willReturn(self::conta(42));
        $this->esperarRecusa('Esta conta já está ativa.');

        $this->useCase->executar(new SituacaoDaContaInput($this->master, 42));
    }

    #[TestDox('conta excluída (excluidoEm preenchido) é recusada: a quarentena não sai por aqui')]
    public function testRecusaContaExcluida(): void
    {
        $conta = self::conta(42)->setIsActive(false)->setExcluidoEm(new \DateTimeImmutable());
        $this->contas->method('buscar')->willReturn($conta);
        $this->esperarRecusa('Esta conta foi excluída e não pode ser reativada por aqui.');

        try {
            $this->useCase->executar(new SituacaoDaContaInput($this->master, 42));
        } finally {
            self::assertFalse($conta->isActive(), 'nada muda');
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

    private static function conta(int $id): Tenant
    {
        $conta = (new Tenant())->setName('Escritório ' . $id);
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($conta, $id);

        return $conta;
    }
}
