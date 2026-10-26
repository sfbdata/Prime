<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\DTO\IdentidadeDoFacebook;
use App\Auth\Service\CadastroPeloFacebook;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

#[CoversClass(CadastroPeloFacebook::class)]
final class CadastroPeloFacebookTest extends TestCase
{
    #[TestDox('A identidade vale por 15 minutos depois da volta do Facebook; depois some da sessão')]
    public function testValidade(): void
    {
        $relogio = new MockClock('2026-10-26 10:00:00');
        $cadastro = new CadastroPeloFacebook($relogio);
        $sessao = new Session(new MockArraySessionStorage());
        $cadastro->guardar($sessao, new IdentidadeDoFacebook('fb-1', 'Ana Souza', null));

        $relogio->modify('+15 minutes');
        self::assertSame('fb-1', $cadastro->ler($sessao)?->id);

        $relogio->modify('+1 second');
        self::assertNull($cadastro->ler($sessao));
        self::assertFalse($sessao->has(CadastroPeloFacebook::CHAVE_SESSAO));
    }

    #[TestDox('Valor estragado na sessão é esquecido, não vira identidade; esquecer limpa a chave')]
    public function testValorEstragado(): void
    {
        $cadastro = new CadastroPeloFacebook(new MockClock());
        $sessao = new Session(new MockArraySessionStorage());
        $sessao->set(CadastroPeloFacebook::CHAVE_SESSAO, 'lixo');

        self::assertNull($cadastro->ler($sessao));
        self::assertFalse($sessao->has(CadastroPeloFacebook::CHAVE_SESSAO));

        $cadastro->guardar($sessao, new IdentidadeDoFacebook('fb-1', 'Ana'));
        CadastroPeloFacebook::esquecer($sessao);
        self::assertNull($cadastro->ler($sessao));
    }
}
