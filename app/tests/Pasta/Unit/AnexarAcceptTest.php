<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * DOC-115: o seletor de arquivo do "Anexar" (`#pexFileInput`) oferece os formatos que o servidor
 * aceita — os do desenho e o `.p7s`, que o servidor já aceitava e faltava aqui. O `accept` é só
 * conveniência do navegador; quem valida é o servidor, pelo conteúdo (ver
 * `AnexarTiposDoDesenhoControllerTest`).
 */
#[CoversNothing]
final class AnexarAcceptTest extends TestCase
{
    private const TWIG = __DIR__ . '/../../../templates/pasta/_documentos_explorador.html.twig';

    #[TestDox('o accept do #pexFileInput lista os tipos novos, o .p7s e mantém os de antes')]
    public function testAcceptListaOsTipos(): void
    {
        $twig = file_get_contents(self::TWIG);
        self::assertIsString($twig);
        self::assertSame(1, preg_match('/<input type="file" id="pexFileInput"[^>]*accept="([^"]+)"/', $twig, $m));

        $aceitas = explode(',', $m[1]);
        $novas   = ['.odt', '.ods', '.odp', '.rtf', '.pptx', '.eml', '.csv', '.gif', '.webp', '.tif', '.tiff', '.heic', '.heif', '.p7s'];
        $antigas = ['.png', '.jpg', '.jpeg', '.pdf', '.kml', '.xml', '.xls', '.xlsx', '.doc', '.docx', '.txt', '.zip', '.mp3', '.ogg', '.opus', '.m4a', '.mp4', '.mov'];

        foreach (array_merge($novas, $antigas) as $extensao) {
            self::assertContains($extensao, $aceitas, $extensao . ' fora do accept');
        }
        self::assertSame(array_values(array_unique($aceitas)), $aceitas, 'sem repetição');
        self::assertNotContains('.html', $aceitas);
        self::assertNotContains('.svg', $aceitas);
    }
}
