<?php

declare(strict_types=1);

namespace App\Tests\Central\Unit;

use App\Central\Entity\ConfiguracaoDePresenca;
use App\Central\Exception\AusenciaRecusada;
use App\Central\Repository\ConfiguracaoDePresencaRepository;
use App\Central\UseCase\AlterarAusenciaDoEscritorioUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Repository\UserTenantRepository;
use App\Tenant\Service\RegraDosPrimarios;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(AlterarAusenciaDoEscritorioUseCase::class)]
final class AlterarAusenciaDoEscritorioUseCaseTest extends TestCase
{
    #[TestDox('O primário grava um valor do desenho; a linha nasce na primeira escolha')]
    public function testPrimarioGrava(): void
    {
        [$user, $tenant, $vinculo] = $this->cenario(primario: true);
        $configuracoes = $this->createMock(ConfiguracaoDePresencaRepository::class);
        $configuracoes->method('doEscritorio')->willReturn(null);
        $salva = null;
        $configuracoes->expects(self::once())->method('salvar')->willReturnCallback(static function (ConfiguracaoDePresenca $c) use (&$salva): void { $salva = $c; });

        $minutos = $this->useCase($configuracoes, $vinculo)->executar($user, $tenant, 30);

        self::assertSame(30, $minutos);
        self::assertSame(30, $salva?->getMinutosAusente());
    }

    #[TestDox('Secundário: recusado com 403 (semPermissao) e nada gravado')]
    public function testSecundarioRecusado(): void
    {
        [$user, $tenant, $vinculo] = $this->cenario(primario: false);
        $configuracoes = $this->createMock(ConfiguracaoDePresencaRepository::class);
        $configuracoes->expects(self::never())->method('salvar');

        try {
            $this->useCase($configuracoes, $vinculo)->executar($user, $tenant, 30);
            self::fail('Secundário não escolhe.');
        } catch (AusenciaRecusada $e) {
            self::assertTrue($e->semPermissao);
        }
    }

    #[TestDox('Valor fora de 15, 30, 60 e 120: recusado com 422 (sem semPermissao)')]
    public function testValorForaDaLista(): void
    {
        [$user, $tenant, $vinculo] = $this->cenario(primario: true);
        $configuracoes = $this->createMock(ConfiguracaoDePresencaRepository::class);
        $configuracoes->expects(self::never())->method('salvar');

        try {
            $this->useCase($configuracoes, $vinculo)->executar($user, $tenant, 45);
            self::fail('45 não é do desenho.');
        } catch (AusenciaRecusada $e) {
            self::assertFalse($e->semPermissao);
        }
    }

    private function useCase(ConfiguracaoDePresencaRepository $configuracoes, UserTenant $vinculo): AlterarAusenciaDoEscritorioUseCase
    {
        $vinculos = $this->createStub(UserTenantRepository::class);
        $vinculos->method('findAtivoPorUserETenant')->willReturn($vinculo);

        return new AlterarAusenciaDoEscritorioUseCase($configuracoes, $vinculos, new RegraDosPrimarios($vinculos), new MockClock('2026-10-19 20:00:00', date_default_timezone_get()));
    }

    /** @return array{0: User, 1: Tenant, 2: UserTenant} */
    private function cenario(bool $primario): array
    {
        $user = (new User())->setIsActive(true);
        $tenant = new Tenant();
        $papel = new TenantRole();
        $papel->setIsSystem($primario);
        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($papel);

        return [$user, $tenant, $vinculo];
    }
}
