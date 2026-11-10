<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Integration;

use App\AssinaturaEletronica\Enum\TipoDeCarta;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Os moldes dos e-mails da Assinatura (`templates/email/assinatura`) no Twig de verdade: o nome do escritório com `&` e
 * apóstrofo sai escapado uma vez só no cabeçalho e no texto (a moldura escapava duas vezes: "Silva &amp;amp; D...").
 */
#[CoversNothing]
final class EmailsDaAssinaturaTest extends KernelTestCase
{
    private const ESCRITORIO = "Silva & D'Ávila Advogados";
    private const ESCAPADO = 'Silva &amp; D&#039;Ávila Advogados';

    /** @return iterable<string, array{TipoDeCarta}> */
    public static function cartasAoSignatario(): iterable
    {
        foreach (TipoDeCarta::cases() as $tipo) {
            if ($tipo->aoSignatario()) {
                yield $tipo->value => [$tipo];
            }
        }
    }

    #[DataProvider('cartasAoSignatario')]
    #[TestDox('$_dataName: o nome do escritório com & e apóstrofo escapado uma vez, no cabeçalho e no texto')]
    public function testEscritorioEscapadoUmaVez(TipoDeCarta $tipo): void
    {
        $html = self::renderizar($tipo);

        self::assertStringContainsString('color:#ffffff;">' . self::ESCAPADO . '</p>', $html, 'o cabeçalho é o nome do escritório');
        self::assertStringNotContainsString('&amp;amp;', $html);
        self::assertStringNotContainsString('&amp;#039;', $html);
        self::assertStringNotContainsString(self::ESCRITORIO, $html, 'nada sai sem escapar');
    }

    /** @return iterable<string, array{TipoDeCarta}> */
    public static function cartasAoRemetente(): iterable
    {
        yield 'recusa' => [TipoDeCarta::Recusa];
        yield 'conclusao' => [TipoDeCarta::Conclusao];
    }

    #[DataProvider('cartasAoRemetente')]
    #[TestDox('$_dataName: ao remetente, o cabeçalho é BlueJus')]
    public function testCabecalhoDoRemetente(TipoDeCarta $tipo): void
    {
        $html = self::renderizar($tipo);

        self::assertStringContainsString('color:#ffffff;">BlueJus</p>', $html);
        self::assertStringNotContainsString('&amp;amp;', $html);
    }

    private static function renderizar(TipoDeCarta $tipo): string
    {
        self::bootKernel();

        return static::getContainer()->get(Environment::class)->render($tipo->template(), [
            'escritorio' => self::ESCRITORIO,
            'documento' => 'Contrato & aditivo',
            'mensagem' => "Leia com atenção & assine.\nObrigado.",
            'nome' => "Ana D'Ávila",
            'motivo' => 'Valor & prazo',
            'link' => 'https://app.bluejus.test/assinar/' . str_repeat('a', 64),
            'codigo' => '123456',
        ]);
    }
}
