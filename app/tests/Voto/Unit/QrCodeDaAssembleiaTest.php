<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Voto\Service\QrCodeDaAssembleia;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QrCodeDaAssembleia::class)]
final class QrCodeDaAssembleiaTest extends TestCase
{
    public function testPngParaImpressaoTemMargemBranca(): void
    {
        $qr = new QrCodeDaAssembleia();
        $png = $qr->png('https://bluejus.com.br/voto/v/' . str_repeat('a', 32));
        $info = getimagesizefromstring($png);
        self::assertNotFalse($info);
        self::assertSame('image/png', $info['mime']);
        self::assertSame(1000, $info[0]);
        self::assertSame(1000, $info[1]);
        $imagem = imagecreatefromstring($png);
        self::assertNotFalse($imagem);
        self::assertSame(0xffffff, imagecolorat($imagem, 0, 0) & 0xffffff);
        self::assertSame(0xffffff, imagecolorat($imagem, 20, 500) & 0xffffff);
        self::assertNotSame($png, $qr->png('https://bluejus.com.br/voto/v/' . str_repeat('b', 32)));
    }

    public function testSvgVetorialTemDimensoesEConteudoDeterministico(): void
    {
        $qr = new QrCodeDaAssembleia();
        $link = 'https://bluejus.com.br/voto/v/' . str_repeat('a', 32);
        $xml = simplexml_load_string($qr->svg($link));
        self::assertNotFalse($xml);
        self::assertSame('svg', $xml->getName());
        self::assertSame($qr->svg($link), $qr->svg($link));
        self::assertNotSame($qr->svg($link), $qr->svg($link . 'b'));
    }
}
