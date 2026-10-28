<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Unit;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Repository\ContasDoSistemaRepository;
use App\Master\Service\RegraDosMasters;
use App\Repository\UserTenantRepository;
use App\Tenant\Exception\AcessoAoEscritorioNegado;
use App\Tenant\Exception\PedidoDeExclusaoRecusado;
use App\Tenant\Service\RegraDosPrimarios;
use App\Tenant\UseCase\ConferirExclusaoDoEscritorioUseCase;
use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(ConferirExclusaoDoEscritorioUseCase::class)]
final class ConferirExclusaoDoEscritorioUseCaseTest extends TestCase
{
    private UserTenantRepository&MockObject $vinculos;
    private ContasDoSistemaRepository&MockObject $contas;
    private UserPasswordHasherInterface&MockObject $hasher;
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->vinculos = $this->createMock(UserTenantRepository::class);
        $this->contas = $this->createMock(ContasDoSistemaRepository::class);
        $this->hasher = $this->createMock(UserPasswordHasherInterface::class);
        $this->tenant = (new Tenant())->setName('Silva Advogados');
        $this->usuario = (new User())->setEmail('p@x.com')->setIsActive(true);
    }

    private function useCase(?RateLimiterFactoryEspiao $limiter = null): ConferirExclusaoDoEscritorioUseCase
    {
        return new ConferirExclusaoDoEscritorioUseCase(
            $this->vinculos,
            new RegraDosPrimarios($this->vinculos),
            new RegraDosMasters($this->contas),
            $this->hasher,
            $limiter ?? new RateLimiterFactoryEspiao(),
        );
    }

    private function comoPrimario(bool $primario): void
    {
        $vinculo = (new UserTenant($this->usuario, $this->tenant))->setTenantRole((new TenantRole())->setName('P')->setIsSystem($primario));
        $this->vinculos->method('findAtivoPorUserETenant')->willReturn($vinculo);
    }

    private function recusa(callable $acao): PedidoDeExclusaoRecusado
    {
        try {
            $acao();
        } catch (PedidoDeExclusaoRecusado $e) {
            return $e;
        }
        self::fail('deveria recusar');
    }

    #[TestDox('senha certa e nome igual (após aparar) passam')]
    public function testPassa(): void
    {
        $this->comoPrimario(true);
        $this->hasher->method('isPasswordValid')->willReturn(true);
        $this->contas->method('existeMasterComVinculoAtivo')->willReturn(false);

        $this->useCase()->executar($this->usuario, $this->tenant, 'senha', '  Silva Advogados ');

        $this->addToAssertionCount(1);
    }

    #[TestDox('senha errada: 422 com "Senha incorreta."')]
    public function testSenhaErrada(): void
    {
        $this->comoPrimario(true);
        $this->hasher->method('isPasswordValid')->willReturn(false);
        $this->contas->method('existeMasterComVinculoAtivo')->willReturn(false);

        $e = $this->recusa(fn () => $this->useCase()->executar($this->usuario, $this->tenant, 'x', 'Silva Advogados'));

        self::assertSame([ConferirExclusaoDoEscritorioUseCase::SENHA_ERRADA, 422], [$e->getMessage(), $e->status]);
    }

    #[TestDox('nome diferente (a comparação é exata, inclusive caixa): 422')]
    public function testNomeDiferente(): void
    {
        $this->comoPrimario(true);
        $this->hasher->method('isPasswordValid')->willReturn(true);
        $this->contas->method('existeMasterComVinculoAtivo')->willReturn(false);

        $e = $this->recusa(fn () => $this->useCase()->executar($this->usuario, $this->tenant, 'senha', 'silva advogados'));

        self::assertSame([ConferirExclusaoDoEscritorioUseCase::NOME_DIFERENTE, 422], [$e->getMessage(), $e->status]);
    }

    #[TestDox('limite de tentativas estourado: 429, sem nem olhar a senha; a chave é o id do usuário')]
    public function testLimite(): void
    {
        $this->comoPrimario(true);
        $this->contas->method('existeMasterComVinculoAtivo')->willReturn(false);
        $this->hasher->expects(self::never())->method('isPasswordValid');

        $e = $this->recusa(fn () => $this->useCase(new RateLimiterFactoryEspiao(aceita: false))->executar($this->usuario, $this->tenant, 'senha', 'Silva Advogados'));

        self::assertSame([ConferirExclusaoDoEscritorioUseCase::MUITAS_TENTATIVAS, 429], [$e->getMessage(), $e->status]);
    }

    #[TestDox('secundário (ou sem vínculo): acesso negado ao escritório')]
    public function testSecundario(): void
    {
        $this->comoPrimario(false);
        $this->expectException(AcessoAoEscritorioNegado::class);

        $this->useCase()->executar($this->usuario, $this->tenant, 'senha', 'Silva Advogados');
    }

    #[TestDox('conta criada por Master: 403, sem exclusão')]
    public function testContaDoMaster(): void
    {
        $this->comoPrimario(true);
        $this->tenant->setCriadoPor((new User())->setEmail('m@x.com')->setRoles(['ROLE_SUPER_ADMIN']));
        $this->hasher->expects(self::never())->method('isPasswordValid');

        $e = $this->recusa(fn () => $this->useCase()->executar($this->usuario, $this->tenant, 'senha', 'Silva Advogados'));

        self::assertSame(403, $e->status);
    }
}
