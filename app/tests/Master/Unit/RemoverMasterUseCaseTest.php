<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Entity\Auth\User;
use App\Master\DTO\RemoverMasterInput;
use App\Master\Exception\OperacaoDeMasterRecusada;
use App\Master\Repository\ContasDoSistemaRepository;
use App\Master\Service\RegraDosMasters;
use App\Master\UseCase\RemoverMasterUseCase;
use App\Repository\UserRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Remover Master secundário (spec perfis-1.2-masters M3, M4, M6, §4.2): caminho feliz e cada recusa.
 */
#[CoversClass(RemoverMasterUseCase::class)]
final class RemoverMasterUseCaseTest extends TestCase
{
    private UserRepository&MockObject $userRepository;
    private EscopoDeAuditoriaDePlataforma $escopo;
    private RemoverMasterUseCase $useCase;
    private User $primario;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->escopo = new EscopoDeAuditoriaDePlataforma();
        $this->useCase  = new RemoverMasterUseCase($this->userRepository, new RegraDosMasters($this->createStub(ContasDoSistemaRepository::class)), $this->escopo);
        $this->primario = $this->usuario(['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO']);
    }

    #[TestDox('o Master primário remove um secundário: só o ROLE_SUPER_ADMIN sai, os outros papéis ficam, e grava')]
    public function testRemoveSecundario(): void
    {
        $alvo = $this->usuario(['ROLE_ALGUM', 'ROLE_SUPER_ADMIN']);
        $this->userRepository->method('find')->with(7)->willReturn($alvo);
        $this->userRepository->expects($this->once())->method('salvar')->with($alvo, true)
            ->willReturnCallback(fn () => self::assertTrue($this->escopo->estaLigado(), 'grava dentro do escopo de plataforma (tenant_id nulo)'));

        $saida = $this->useCase->executar(new RemoverMasterInput($this->primario, 7));

        self::assertSame(['ROLE_ALGUM'], RegraDosMasters::papeisGravados($alvo));
        self::assertSame('Alvo', $saida->nome);
    }

    #[TestDox('Master secundário não remove ninguém')]
    public function testRecusaMasterSecundario(): void
    {
        $this->userRepository->method('find')->willReturn($this->usuario(['ROLE_SUPER_ADMIN']));
        $this->esperarRecusa('/Só o Master primário/');

        $this->useCase->executar(new RemoverMasterInput($this->usuario(['ROLE_SUPER_ADMIN']), 7));
    }

    #[TestDox('alvo inexistente é recusado')]
    public function testRecusaAlvoInexistente(): void
    {
        $this->userRepository->method('find')->willReturn(null);
        $this->esperarRecusa('/não é Master/');

        $this->useCase->executar(new RemoverMasterInput($this->primario, 999));
    }

    #[TestDox('alvo que não é Master é recusado')]
    public function testRecusaAlvoQueNaoEhMaster(): void
    {
        $this->userRepository->method('find')->willReturn($this->usuario(['ROLE_ALGUM']));
        $this->esperarRecusa('/não é Master/');

        $this->useCase->executar(new RemoverMasterInput($this->primario, 7));
    }

    #[TestDox('o Master primário nunca é removido (nem por ele mesmo)')]
    public function testRecusaRemoverOPrimario(): void
    {
        $this->userRepository->method('find')->willReturn($this->primario);
        $this->esperarRecusa('/Master primário não pode ser excluído/');

        $this->useCase->executar(new RemoverMasterInput($this->primario, 1));
    }

    private function esperarRecusa(string $padrao): void
    {
        $this->userRepository->expects($this->never())->method('salvar');
        $this->expectException(OperacaoDeMasterRecusada::class);
        $this->expectExceptionMessageMatches($padrao);
    }

    /** @param list<string> $papeis */
    private function usuario(array $papeis): User
    {
        $user = new User();
        $user->setEmail(uniqid('master_') . '@test.com');
        $user->setFullName('Alvo');
        $user->setRoles($papeis);
        $user->setIsActive(true);

        return $user;
    }
}
