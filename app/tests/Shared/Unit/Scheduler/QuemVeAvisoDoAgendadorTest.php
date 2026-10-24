<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Scheduler;

use App\Entity\Auth\User;
use App\Shared\Scheduler\QuemVeAvisoDoAgendador;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Quem vê o aviso de falha do agendador (spec scheduler-tarefas-agendadas, "Aviso de falha"): o Master, ou o
 * e-mail de AGENDADOR_ALERTA_EMAIL sem diferença de maiúsculas. Variável vazia: só o Master.
 */
#[CoversClass(QuemVeAvisoDoAgendador::class)]
final class QuemVeAvisoDoAgendadorTest extends TestCase
{
    public function testMasterVeMesmoSemEnderecoDeAlerta(): void
    {
        self::assertTrue((new QuemVeAvisoDoAgendador(''))->podeVer($this->usuario('master@exemplo.com', ['ROLE_SUPER_ADMIN'])));
    }

    public function testEnderecoDeAlertaVeSemDiferencaDeMaiusculasEEntreVarios(): void
    {
        $regra = new QuemVeAvisoDoAgendador(' ops@exemplo.com , Dono@Exemplo.com ');

        self::assertTrue($regra->podeVer($this->usuario('dono@exemplo.COM')));
        self::assertTrue($regra->podeVer($this->usuario('OPS@exemplo.com')));
    }

    public function testUsuarioComumNaoVe(): void
    {
        self::assertFalse((new QuemVeAvisoDoAgendador('ops@exemplo.com'))->podeVer($this->usuario('fulano@exemplo.com')));
    }

    public function testVariavelVaziaNaoLiberaNinguemAlemDoMaster(): void
    {
        $regra = new QuemVeAvisoDoAgendador(' , ');

        self::assertFalse($regra->podeVer($this->usuario('fulano@exemplo.com')));
        self::assertFalse($regra->podeVer($this->usuario('')));
    }

    public function testSemUsuarioOuOutroTipoDeUsuarioNaoVe(): void
    {
        $regra = new QuemVeAvisoDoAgendador('ops@exemplo.com');

        self::assertFalse($regra->podeVer(null));
        self::assertFalse($regra->podeVer(new InMemoryUser('ops@exemplo.com', null, ['ROLE_SUPER_ADMIN'])));
    }

    /** @param list<string> $papeis */
    private function usuario(string $email, array $papeis = ['ROLE_USER']): User
    {
        return (new User())->setEmail($email)->setRoles($papeis);
    }
}
