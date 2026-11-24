<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\Service\ConvenioDoCadastro;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * O `?convenio=` do link de cadastro (spec docs/specs/convenios.md, §5.3, PR 5): guarda só o que tem a forma de um
 * código, já como o Master o vê.
 */
#[CoversClass(ConvenioDoCadastro::class)]
final class ConvenioDoCadastroTest extends TestCase
{
    #[TestDox('guarda o código normalizado (maiúsculas, sem espaço nem hífen)')]
    public function testGuarda(): void
    {
        $sessao = new Session(new MockArraySessionStorage());

        ConvenioDoCadastro::guardar($sessao, ' abcd-2345 ');

        self::assertSame('ABCD2345', $sessao->get(ConvenioDoCadastro::CHAVE_SESSAO));
    }

    /** @return iterable<string, array{mixed}> */
    public static function naoCodigos(): iterable
    {
        yield 'sem o parâmetro' => [null];
        yield 'vazio' => [''];
        yield 'lista' => [['ABCD2345']];
        yield 'curto' => ['AB1'];
        yield 'longo demais' => [str_repeat('A', 17)];
        yield 'com símbolo' => ['ABCD<2345>'];
    }

    #[TestDox('o que não tem a forma de um código não muda a sessão')]
    #[DataProvider('naoCodigos')]
    public function testIgnora(mixed $valor): void
    {
        $sessao = new Session(new MockArraySessionStorage());
        $sessao->set(ConvenioDoCadastro::CHAVE_SESSAO, 'ANTERIOR');

        ConvenioDoCadastro::guardar($sessao, $valor);

        self::assertSame('ANTERIOR', $sessao->get(ConvenioDoCadastro::CHAVE_SESSAO));
    }
}
