<?php

declare(strict_types=1);

namespace App\Tests\ChatIa\Unit;

use App\ChatIa\Exception\TetoDiarioDoChatException;
use App\ChatIa\Repository\MensagemDeChatRepository;
use App\ChatIa\Service\TetoDeUsoDoChat;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(TetoDeUsoDoChat::class)]
final class TetoDeUsoDoChatTest extends TestCase
{
    private function teto(int $usado, int $dia = 1000, int $resposta = 2000): TetoDeUsoDoChat
    {
        $repo = $this->createStub(MensagemDeChatRepository::class);
        $repo->method('tokensDaPessoaDesde')->willReturn($usado);

        return new TetoDeUsoDoChat($repo, new MockClock('2026-10-28 15:00:00'), $resposta, $dia);
    }

    #[TestDox('Abaixo do teto diário passa; no teto ou acima lança 429')]
    public function testTetoDiario(): void
    {
        $tenant = new Tenant();
        $pessoa = new User();
        $this->teto(999)->conferirODia($pessoa, $tenant);
        $this->addToAssertionCount(1);

        $this->expectException(TetoDiarioDoChatException::class);
        $this->teto(1000)->conferirODia($pessoa, $tenant);
    }

    #[TestDox('Teto por resposta nunca fica abaixo de 16 tokens')]
    public function testPorResposta(): void
    {
        self::assertSame(2000, $this->teto(0)->porResposta());
        self::assertSame(16, $this->teto(0, 1000, 1)->porResposta());
    }
}
