<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\PublicoDosAvisosDePonto;
use App\Repository\UserTenantRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * O público dos avisos de ponto (decisão do dono, 23/10; spec `ponto-esquecimento-nao-trava.md` R7): a chave ligada
 * no escritório OU batida aqui nos últimos 30 dias. A consulta da batida é a `ultimaBatidaNoTenant`, já provada.
 */
#[CoversClass(PublicoDosAvisosDePonto::class)]
final class PublicoDosAvisosDePontoTest extends TestCase
{
    #[TestDox('Chave ligada: recebe, sem consultar as batidas')]
    public function testChaveLigadaRecebe(): void
    {
        $registros = $this->createMock(RegistroPontoRepository::class);
        $registros->expects(self::never())->method('ultimaBatidaNoTenant');

        self::assertTrue($this->publico($this->vinculo(true), $registros)->recebe(new User(), new Tenant()));
    }

    #[TestDox('Chave desligada (ou sem vínculo) e batida nos últimos 30 dias: recebe; a janela é de 30 dias até agora')]
    public function testBatidaRecenteRecebe(): void
    {
        $registros = $this->createMock(RegistroPontoRepository::class);
        $registros->expects(self::exactly(2))->method('ultimaBatidaNoTenant')
            ->willReturnCallback(static function (User $u, Tenant $t, \DateTimeImmutable $inicio, \DateTimeImmutable $ate): RegistroPonto {
                self::assertSame('2026-09-23 10:00', $inicio->format('Y-m-d H:i'));
                self::assertSame('2026-10-23 10:00', $ate->format('Y-m-d H:i'));

                return new RegistroPonto();
            });

        self::assertTrue($this->publico($this->vinculo(false), $registros)->recebe(new User(), new Tenant()));
        self::assertTrue($this->publico($this->vinculo(null), $registros)->recebe(new User(), new Tenant()));
    }

    #[TestDox('Chave desligada e sem batida nos últimos 30 dias: não recebe')]
    public function testSemChaveESemBatidaNaoRecebe(): void
    {
        $registros = $this->createMock(RegistroPontoRepository::class);
        $registros->method('ultimaBatidaNoTenant')->willReturn(null);

        self::assertFalse($this->publico($this->vinculo(false), $registros)->recebe(new User(), new Tenant()));
    }

    private function publico(UserTenantRepository $vinculos, RegistroPontoRepository $registros): PublicoDosAvisosDePonto
    {
        return new PublicoDosAvisosDePonto($vinculos, $registros, new MockClock('2026-10-23 10:00:00'));
    }

    /** @param ?bool $registraPonto null = sem vínculo ativo */
    private function vinculo(?bool $registraPonto): UserTenantRepository
    {
        $vinculos = $this->createMock(UserTenantRepository::class);
        if ($registraPonto === null) {
            $vinculos->method('findAtivoPorUserETenant')->willReturn(null);

            return $vinculos;
        }
        $vinculo = new UserTenant(new User(), new Tenant());
        $vinculo->alterarRegistroDePonto($registraPonto);
        $vinculos->method('findAtivoPorUserETenant')->willReturn($vinculo);

        return $vinculos;
    }
}
