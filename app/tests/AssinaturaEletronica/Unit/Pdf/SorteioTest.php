<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\Pdf;

use App\AssinaturaEletronica\Pdf\Sorteio;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O fundo de cada página sai do sorteio do desenho (`rnd` em dc:307): os valores esperados foram tirados rodando o
 * código do desenho no node, com o mesmo hash e a mesma página. Igualdade exata: qualquer diferença muda a folha.
 */
#[CoversClass(Sorteio::class)]
final class SorteioTest extends TestCase
{
    private const HASHES = [
        '00000000' => '0000000012ab34cd56ef7890aa55bb66cc77dd88ee99ff0011223344556677ff',
        'ffffffff' => 'ffffffffaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        '9f86d081' => '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08',
    ];

    /** @return iterable<string, array{string, int, float, float, float, float, int}> */
    public static function doNode(): iterable
    {
        yield 'semente zero, página 1' => ['00000000', 1, 0.40100111346691847, 0.02039375328831375, 0.04290273960679769, 0.732233458198607, 9];
        yield 'semente zero, página 2' => ['00000000', 2, 0.7995421621017158, 0.015104116461239755, 0.03780812153592705, 2.1263407298829406, 10];
        yield 'semente zero, página 7' => ['00000000', 7, 2.850008048582822, 0.020848398031666875, 0.041385697149671614, 2.0491649138275534, 9];
        yield 'semente máxima, página 1' => ['ffffffff', 1, 0.4214198193512857, 0.015873030667193234, 0.03696245095226913, 1.7763979397714138, 12];
        yield 'semente máxima, página 2' => ['ffffffff', 2, 0.8424848718568683, 0.019580555621534587, 0.04335013401228935, 0.39514373056590557, 10];
        yield 'semente máxima, página 7' => ['ffffffff', 7, 2.85888974647969, 0.022324671722017228, 0.0498515510186553, 0.6003987940493971, 11];
        yield 'hash comum, página 1' => ['9f86d081', 1, 5.407835595309734, 0.020954528175294397, 0.03108858849387616, 0.11836441070772707, 12];
        yield 'hash comum, página 2' => ['9f86d081', 2, 4.888454691041261, 0.015195182835683227, 0.03329728618264198, 2.4101684633642435, 12];
        yield 'hash comum, página 7' => ['9f86d081', 7, 0.3411747948266566, 0.01392586229648441, 0.04327298032585532, 1.7133098866324872, 13];
    }

    #[DataProvider('doNode')]
    #[TestDox('Fase, frequências, giro e pétalas iguais aos do desenho: $_dataName')]
    public function testIgualAoDesenho(string $prefixo, int $pagina, float $fase, float $frequencia1, float $frequencia2, float $giro, int $petalas): void
    {
        $sorteio = Sorteio::daPagina(self::HASHES[$prefixo], $pagina);

        // A mesma ordem e as mesmas contas de `fundo(g, pn)` (dc:409).
        self::assertSame($fase, $sorteio->proximo() * 6);
        self::assertSame($frequencia1, .012 + $sorteio->proximo() * .012);
        self::assertSame($frequencia2, .03 + $sorteio->proximo() * .02);
        self::assertSame($giro, $sorteio->proximo() * 3);
        self::assertSame($petalas, 9 + (int) floor($sorteio->proximo() * 5));
    }

    #[TestDox('Cada página tem o seu sorteio, e o mesmo hash repete o mesmo fundo')]
    public function testPaginasDiferentesEReproduzivel(): void
    {
        $hash = self::HASHES['9f86d081'];

        self::assertSame(Sorteio::daPagina($hash, 3)->proximo(), Sorteio::daPagina($hash, 3)->proximo());
        self::assertNotSame(Sorteio::daPagina($hash, 3)->proximo(), Sorteio::daPagina($hash, 4)->proximo());
    }

    #[TestDox('O valor sorteado fica entre 0 e 1')]
    public function testIntervalo(): void
    {
        $sorteio = Sorteio::daPagina(self::HASHES['ffffffff'], 1);
        for ($i = 0; $i < 500; ++$i) {
            $valor = $sorteio->proximo();
            self::assertGreaterThanOrEqual(0.0, $valor);
            self::assertLessThan(1.0, $valor);
        }
    }
}
