<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Ponto\Service\ChavesDoHmacDoPonto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * As chaves HMAC do Ponto (E9): derivadas do `PONTO_HMAC_SECRET` por finalidade e versão; sem segredo, nada lança.
 */
#[CoversClass(ChavesDoHmacDoPonto::class)]
final class ChavesDoHmacDoPontoTest extends TestCase
{
    #[TestDox('Com segredo: disponível, versão 1 e a chave é o HKDF do segredo com a finalidade, 32 bytes')]
    public function testComSegredo(): void
    {
        $chaves = new ChavesDoHmacDoPonto('segredo-de-teste');

        self::assertTrue($chaves->disponivel());
        self::assertSame(1, $chaves->versaoAtual());
        self::assertSame(hash_hkdf('sha256', 'segredo-de-teste', 32, 'ponto-trilha'), $chaves->chave(ChavesDoHmacDoPonto::FINALIDADE_TRILHA, 1));
        self::assertSame(32, \strlen((string) $chaves->chave('ponto-trilha', 1)));
    }

    #[TestDox('A chave muda com a finalidade e com o segredo, e é a mesma em chamadas repetidas')]
    public function testChavePorFinalidadeESegredo(): void
    {
        $chaves = new ChavesDoHmacDoPonto('segredo-de-teste');

        self::assertSame($chaves->chave('ponto-trilha', 1), $chaves->chave('ponto-trilha', 1));
        self::assertNotSame($chaves->chave('ponto-trilha', 1), $chaves->chave('ponto-comprovante', 1));
        self::assertNotSame($chaves->chave('ponto-trilha', 1), (new ChavesDoHmacDoPonto('outro-segredo'))->chave('ponto-trilha', 1));
    }

    #[TestDox('Sem segredo (vazio ou ausente): indisponível, sem versão e sem chave, sem exceção')]
    #[DataProvider('semSegredo')]
    public function testSemSegredo(?string $segredo): void
    {
        $chaves = new ChavesDoHmacDoPonto($segredo);

        self::assertFalse($chaves->disponivel());
        self::assertNull($chaves->versaoAtual());
        self::assertNull($chaves->chave(ChavesDoHmacDoPonto::FINALIDADE_TRILHA, 1));
    }

    /** @return iterable<string, array{?string}> */
    public static function semSegredo(): iterable
    {
        yield 'vazio' => [''];
        yield 'ausente' => [null];
    }

    #[TestDox('Versão desconhecida: chave nula, sem exceção')]
    public function testVersaoDesconhecida(): void
    {
        $chaves = new ChavesDoHmacDoPonto('segredo-de-teste');

        self::assertNull($chaves->chave(ChavesDoHmacDoPonto::FINALIDADE_TRILHA, 2));
        self::assertNull($chaves->chave(ChavesDoHmacDoPonto::FINALIDADE_TRILHA, 0));
    }
}
