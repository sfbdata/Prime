<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Integration;

use App\Tenant\EventListener\BarreiraDoEscritorioInativoNaRota;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Idioma do sistema (spec docs/specs/idioma-do-sistema.md §27): o título da página de escritório indisponível
 * continua em português na constante (`BarreiraDoEscritorioInativoNaRota::ROTULO`) e a página traduz pela chave.
 * O português do catálogo tem de ser o mesmo da constante, senão a tela muda.
 */
#[CoversNothing]
final class CatalogoDoEscritorioTest extends KernelTestCase
{
    #[TestDox('O título do escritório indisponível tem chave no catálogo, com o mesmo texto em português')]
    public function testRotuloDoIndisponivelNoCatalogo(): void
    {
        self::bootKernel();
        $tradutor = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $tradutor);

        self::assertSame(
            BarreiraDoEscritorioInativoNaRota::ROTULO,
            $tradutor->trans(BarreiraDoEscritorioInativoNaRota::CHAVE_DO_ROTULO, [], null, 'pt_BR'),
        );
    }
}
