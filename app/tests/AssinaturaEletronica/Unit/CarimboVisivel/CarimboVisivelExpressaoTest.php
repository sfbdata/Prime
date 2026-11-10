<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\CarimboVisivel;

use App\AssinaturaEletronica\Service\CarimboVisivelExpressao;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(CarimboVisivelExpressao::class)]
final class CarimboVisivelExpressaoTest extends TestCase
{
    #[TestDox('casa, não casa, com posição e com deslocamento')]
    public function testCasar(): void
    {
        self::assertSame(['12 0 R', '12'], CarimboVisivelExpressao::casar('/(\d++) 0 R/', 'x 12 0 R'));
        self::assertNull(CarimboVisivelExpressao::casar('/(\d++) 0 R/', 'x'));
        self::assertSame([['7', 4]], CarimboVisivelExpressao::casar('/\G\d/', 'abc 7', \PREG_OFFSET_CAPTURE, 4));
        self::assertSame([['1 0 R', '1'], ['2 0 R', '2']], CarimboVisivelExpressao::todas('/(\d) 0 R/', '[1 0 R 2 0 R]'));
        self::assertSame(['1', '2', '3 4'], CarimboVisivelExpressao::dividir('/\s++/', '1 2 3 4', 3));
    }

    #[TestDox('falha do PCRE lança, nas três formas: nunca passa por "não casou"')]
    public function testFalha(): void
    {
        $limite = (string) ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');
        $falhas = [];
        try {
            foreach ([
                'casar' => static fn (): mixed => CarimboVisivelExpressao::casar('/(\d++)\s++(\d++)\s++obj/', "%PDF\n1 0 obj"),
                'todas' => static fn (): mixed => CarimboVisivelExpressao::todas('/(\d++)\s++(\d++)\s++R/', '[1 0 R 2 0 R]'),
                'dividir' => static fn (): mixed => CarimboVisivelExpressao::dividir('/(\d++)\s++(\d++)\s++obj/', "%PDF\n1 0 obj", 3),
            ] as $forma => $chamar) {
                try {
                    $chamar();
                } catch (\RuntimeException $falha) {
                    $falhas[$forma] = $falha->getMessage();
                }
            }
        } finally {
            ini_set('pcre.backtrack_limit', $limite);
        }

        self::assertSame(['casar', 'todas', 'dividir'], array_keys($falhas));
        self::assertStringContainsString('Backtrack limit', $falhas['casar']);
    }
}
