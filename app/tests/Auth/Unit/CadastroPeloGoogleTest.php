<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\DTO\IdentidadeDoGoogle;
use App\Auth\Service\CadastroPeloGoogle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

#[CoversClass(CadastroPeloGoogle::class)]
final class CadastroPeloGoogleTest extends TestCase
{
    #[TestDox('A identidade vale por 15 minutos depois da volta do Google; depois some da sessão')]
    public function testValidade(): void
    {
        $relogio = new MockClock('2026-10-24 10:00:00');
        $cadastro = new CadastroPeloGoogle($relogio);
        $sessao = new Session(new MockArraySessionStorage());
        $cadastro->guardar($sessao, new IdentidadeDoGoogle('sub-1', 'ana@gmail.com', 'Ana Souza'));

        $relogio->modify('+15 minutes');
        self::assertSame('sub-1', $cadastro->ler($sessao)?->sub);

        $relogio->modify('+1 second');
        self::assertNull($cadastro->ler($sessao));
        self::assertFalse($sessao->has(CadastroPeloGoogle::CHAVE_SESSAO));
    }

    #[TestDox('Valor estragado na sessão é esquecido, não vira identidade')]
    public function testValorEstragado(): void
    {
        $cadastro = new CadastroPeloGoogle(new MockClock());
        $sessao = new Session(new MockArraySessionStorage());
        $sessao->set(CadastroPeloGoogle::CHAVE_SESSAO, 'lixo');

        self::assertNull($cadastro->ler($sessao));
        self::assertFalse($sessao->has(CadastroPeloGoogle::CHAVE_SESSAO));
    }
}
