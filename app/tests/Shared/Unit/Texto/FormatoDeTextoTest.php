<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Texto;

use App\Shared\Texto\FormatoDeTexto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(FormatoDeTexto::class)]
final class FormatoDeTextoTest extends TestCase
{
    /** @return iterable<string, array{string, ?FormatoDeTexto}> */
    public static function tipos(): iterable
    {
        yield 'docx exato' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', FormatoDeTexto::Docx];
        yield 'maiúsculas do doc' => ['  Application/MsWord ', FormatoDeTexto::Doc];
        yield 'com charset' => ['text/plain; charset=utf-8', FormatoDeTexto::Txt];
        yield 'sinônimo rtf' => ['text/rtf', FormatoDeTexto::Rtf];
        yield 'rtf' => ['application/rtf', FormatoDeTexto::Rtf];
        yield 'sinônimo xml' => ['TEXT/XML', FormatoDeTexto::Xml];
        yield 'kml' => ['application/vnd.google-earth.kml+xml', FormatoDeTexto::Kml];
        yield 'html da peça' => ['text/html;charset=UTF-8', FormatoDeTexto::PecaDoEditor];
        yield 'desconhecido' => ['application/pdf', null];
        yield 'vazio' => ['', null];
    }

    #[DataProvider('tipos')]
    #[TestDox('O tipo declarado vira o formato (ou null)')]
    public function testDoTipoDeclarado(string $tipo, ?FormatoDeTexto $esperado): void
    {
        self::assertSame($esperado, FormatoDeTexto::doTipoDeclarado($tipo));
    }

    #[TestDox('Tetos de tamanho: 5 MB para texto puro e HTML, 10 MB para os formatos de pacote')]
    public function testTamanhoMaximo(): void
    {
        foreach ([FormatoDeTexto::Txt, FormatoDeTexto::Csv, FormatoDeTexto::Xml, FormatoDeTexto::Kml, FormatoDeTexto::PecaDoEditor] as $formato) {
            self::assertSame(5 * 1024 * 1024, $formato->tamanhoMaximo());
        }
        foreach ([FormatoDeTexto::Docx, FormatoDeTexto::Doc, FormatoDeTexto::Odt, FormatoDeTexto::Rtf] as $formato) {
            self::assertSame(10 * 1024 * 1024, $formato->tamanhoMaximo());
        }
    }
}
