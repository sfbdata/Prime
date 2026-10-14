<?php

declare(strict_types=1);

namespace App\Tests\Central\Unit;

use App\Central\Exception\ConversaNaoEncontradaException;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Repository\ParticipanteRepository;
use App\Central\Service\AcessoACentral;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A guarda da Central sozinha. No teste HTTP, quem não tem vínculo é barrado ANTES pela plataforma (manda
 * escolher escritório) — aquele teste prova o isolamento, não esta guarda. Aqui ela é a única barreira.
 */
#[CoversClass(AcessoACentral::class)]
final class AcessoACentralTest extends TestCase
{
    #[TestDox('Sem escritório na sessão ou sem vínculo ativo: recusa')]
    public function testSemVinculo(): void
    {
        $vinculos = $this->createMock(UserTenantRepository::class);
        $vinculos->method('existeVinculoAtivo')->willReturn(false);
        $acesso = new AcessoACentral($vinculos, $this->createStub(ParticipanteRepository::class), $this->createStub(UserRepository::class));

        foreach ([null, new Tenant()] as $tenant) {
            try {
                $acesso->exigirVinculoAtivo(new User(), $tenant);
                self::fail('Deveria recusar.');
            } catch (SemAcessoACentralException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[TestDox('Com vínculo ativo: devolve o escritório da sessão')]
    public function testComVinculo(): void
    {
        $tenant = new Tenant();
        $usuario = new User();
        $vinculos = $this->createMock(UserTenantRepository::class);
        $vinculos->expects(self::once())->method('existeVinculoAtivo')->with($usuario, $tenant)->willReturn(true);

        self::assertSame($tenant, (new AcessoACentral($vinculos, $this->createStub(ParticipanteRepository::class), $this->createStub(UserRepository::class)))->exigirVinculoAtivo($usuario, $tenant));
    }

    #[TestDox('Participação procurada com o usuário e o escritório; sem ela, "não encontrada"')]
    public function testParticipacao(): void
    {
        $tenant = new Tenant();
        $usuario = new User();
        $participantes = $this->createMock(ParticipanteRepository::class);
        $participantes->expects(self::once())->method('daConversa')->with(9, $usuario, $tenant)->willReturn(null);

        $this->expectException(ConversaNaoEncontradaException::class);

        (new AcessoACentral($this->createStub(UserTenantRepository::class), $participantes, $this->createStub(UserRepository::class)))->participacao(9, $usuario, $tenant);
    }
}
