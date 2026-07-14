<?php

declare(strict_types=1);

namespace App\Tests\Sync\Unit;

use App\Sync\Service\ExtratorDeFolderId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExtratorDeFolderId::class)]
final class ExtratorDeFolderIdTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string}> */
    public static function entradasValidas(): iterable
    {
        yield 'id cru' => ['1WBY-hLuad3weKWPBM0f297_RtWTJo0_R', '1WBY-hLuad3weKWPBM0f297_RtWTJo0_R'];
        yield 'url /folders/' => ['https://drive.google.com/drive/folders/1WBY-hLuad3weKWPBM0f297_RtWTJo0_R', '1WBY-hLuad3weKWPBM0f297_RtWTJo0_R'];
        yield 'url /u/0/folders/' => ['https://drive.google.com/drive/u/0/folders/1AbCdEfGhIjKlMnOpQrStUvWxYz012345', '1AbCdEfGhIjKlMnOpQrStUvWxYz012345'];
        yield 'url com querystring' => ['https://drive.google.com/drive/folders/1WBY-hLuad3weKWPBM0f297_RtWTJo0_R?usp=sharing', '1WBY-hLuad3weKWPBM0f297_RtWTJo0_R'];
        yield 'url open?id=' => ['https://drive.google.com/open?id=1WBY-hLuad3weKWPBM0f297_RtWTJo0_R', '1WBY-hLuad3weKWPBM0f297_RtWTJo0_R'];
        yield 'com espacos ao redor' => ['   1WBY-hLuad3weKWPBM0f297_RtWTJo0_R   ', '1WBY-hLuad3weKWPBM0f297_RtWTJo0_R'];
    }

    #[DataProvider('entradasValidas')]
    #[TestDox('extrai o folder id de: $_dataName')]
    public function testExtrai(string $entrada, string $esperado): void
    {
        self::assertSame($esperado, (new ExtratorDeFolderId())->extrair($entrada));
    }

    /** @return iterable<string, array{0: string}> */
    public static function entradasInvalidas(): iterable
    {
        yield 'vazio' => [''];
        yield 'so espacos' => ['   '];
        yield 'texto curto' => ['abc'];
        yield 'url sem id' => ['https://drive.google.com/drive/my-drive'];
    }

    #[DataProvider('entradasInvalidas')]
    #[TestDox('rejeita entrada inválida: $_dataName')]
    public function testRejeita(string $entrada): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ExtratorDeFolderId())->extrair($entrada);
    }
}
