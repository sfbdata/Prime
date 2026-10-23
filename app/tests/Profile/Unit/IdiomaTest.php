<?php

declare(strict_types=1);

namespace App\Tests\Profile\Unit;

use App\Profile\Enum\Idioma;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(Idioma::class)]
final class IdiomaTest extends TestCase
{
    #[TestDox('os 10 idiomas do desenho, na ordem do seletor, com nome no próprio idioma e sigla')]
    public function testListaDoDesenho(): void
    {
        self::assertSame(['pt-BR', 'en', 'es', 'fr', 'it', 'de', 'ru', 'zh', 'ja', 'ar'], array_map(static fn (Idioma $i): string => $i->value, Idioma::cases()));
        self::assertSame('Português (Brasil)', Idioma::PortuguesBrasil->nome());
        self::assertSame('العربية', Idioma::Arabe->nome());
        self::assertSame('PT', Idioma::PortuguesBrasil->sigla());
        self::assertSame('ZH', Idioma::Chines->sigla());
    }

    #[TestDox('locale do Symfony com sublinhado; só o árabe é da direita para a esquerda')]
    public function testLocaleEDirecao(): void
    {
        self::assertSame('pt_BR', Idioma::PortuguesBrasil->locale());
        self::assertSame('en', Idioma::Ingles->locale());
        self::assertSame('rtl', Idioma::Arabe->direcao());
        self::assertSame('ltr', Idioma::Japones->direcao());
    }

    #[TestDox('o seletor oferece português, inglês e espanhol; só o português está liberado; o gravado desconhecido, nulo ou não liberado vale como português')]
    public function testEmUso(): void
    {
        self::assertSame([Idioma::PortuguesBrasil, Idioma::Ingles, Idioma::Espanhol], Idioma::oferecidos());
        self::assertSame([Idioma::PortuguesBrasil], array_values(array_filter(Idioma::cases(), static fn (Idioma $i): bool => $i->ativo())));
        self::assertSame(Idioma::PortuguesBrasil, Idioma::emUso('pt-BR'));
        self::assertSame(Idioma::PortuguesBrasil, Idioma::emUso('en'));
        self::assertSame(Idioma::PortuguesBrasil, Idioma::emUso('es'));
        self::assertSame(Idioma::PortuguesBrasil, Idioma::emUso('fr'));
        self::assertSame(Idioma::PortuguesBrasil, Idioma::emUso('ar'));
        self::assertSame(Idioma::PortuguesBrasil, Idioma::emUso('xx'));
        self::assertSame(Idioma::PortuguesBrasil, Idioma::emUso(null));
    }
}
