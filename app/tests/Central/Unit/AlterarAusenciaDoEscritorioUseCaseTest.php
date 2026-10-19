<?php

declare(strict_types=1);

namespace App\Tests\Central\Unit;

use App\Central\Entity\ConfiguracaoDePresenca;
use App\Central\Exception\AusenciaRecusadaException;
use App\Central\Repository\ConfiguracaoDePresencaRepository;
use App\Central\UseCase\AlterarAusenciaDoEscritorioUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Repository\UserTenantRepository;
use App\Tenant\Service\RegraDosPrimarios;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $configuracoes->expects(self::once())->method('salvar')->willReturnCallback(static function (ConfiguracaoDePresenca $c) use (&$salva): void {
            $salva = $c;
        });

        $minutos = $this->useCase($configuracoes, $vinculo)->executar($user, $tenant, 30);

        self::assertSame(30, $minutos);
        self::assertSame(30, $salva?->getMinutosAusente());
    }

    /** @return iterable<string, array{0: bool, 1: bool, 2: list<string>, 3: bool}> */
    public static function quemNaoEhPrimario(): iterable
    {
        yield 'secundário (perfil comum)' => [false, true, ['ROLE_USER'], true];
        yield 'conta desativada, mesmo com perfil de sistema' => [true, false, ['ROLE_USER'], true];
        yield 'Master sem perfil de sistema no escritório' => [false, true, ['ROLE_SUPER_ADMIN'], true];
        yield 'sem vínculo ativo no escritório' => [true, true, ['ROLE_USER'], false];
    }

    /** @param list<string> $papeis */
    #[DataProvider('quemNaoEhPrimario')]
    #[TestDox('Não primário ($_dataName): recusado com 403 e nada gravado')]
    public function testNaoPrimarioRecusado(bool $perfilDeSistema, bool $contaAtiva, array $papeis, bool $comVinculo): void
    {
        [$user, $tenant, $vinculo] = $this->cenario(primario: $perfilDeSistema);
        $user->setIsActive($contaAtiva)->setRoles($papeis);
        $configuracoes = $this->createMock(ConfiguracaoDePresencaRepository::class);
        $configuracoes->expects(self::never())->method('salvar');

        $e = $this->recusa(fn () => $this->useCase($configuracoes, $comVinculo ? $vinculo : null)->executar($user, $tenant, 30));

        self::assertSame(AusenciaRecusadaException::SEM_PERMISSAO, $e->motivo);
        self::assertSame('Só o usuário primário do escritório escolhe este tempo.', $e->getMessage());
    }

    #[TestDox('Valor fora de 15, 30, 60 e 120: recusado com 422 e nada gravado')]
    public function testValorForaDaLista(): void
    {
        [$user, $tenant, $vinculo] = $this->cenario(primario: true);
        $configuracoes = $this->createMock(ConfiguracaoDePresencaRepository::class);
        $configuracoes->expects(self::never())->method('salvar');

        $e = $this->recusa(fn () => $this->useCase($configuracoes, $vinculo)->executar($user, $tenant, 45));

        self::assertSame(AusenciaRecusadaException::FORA_DA_LISTA, $e->motivo);
    }

    #[TestDox('Dois primários gravam a primeira escolha juntos: a UNIQUE recusa o segundo, que recebe 409 (não 500)')]
    public function testPrimeiraEscolhaConcorrente(): void
    {
        [$user, $tenant, $vinculo] = $this->cenario(primario: true);
        $configuracoes = $this->createStub(ConfiguracaoDePresencaRepository::class);
        $configuracoes->method('doEscritorio')->willReturn(null);
        $configuracoes->method('salvar')->willThrowException($this->createStub(UniqueConstraintViolationException::class));

        $e = $this->recusa(fn () => $this->useCase($configuracoes, $vinculo)->executar($user, $tenant, 15));

        self::assertSame(AusenciaRecusadaException::CONCORRENTE, $e->motivo);
    }

    private function recusa(\Closure $acao): AusenciaRecusadaException
    {
        try {
            $acao();
        } catch (AusenciaRecusadaException $e) {
            return $e;
        }
        self::fail('Era para recusar.');
    }

    private function useCase(ConfiguracaoDePresencaRepository $configuracoes, ?UserTenant $vinculo): AlterarAusenciaDoEscritorioUseCase
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
