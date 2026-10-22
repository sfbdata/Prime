<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Integration;

use App\Tenant\EventListener\BarreiraDoEscritorioInativoNaRota;
use App\Tenant\Service\RegraDosPrimarios;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Idioma do sistema (spec docs/specs/idioma-do-sistema.md §27): o título da página de escritório indisponível
 * continua em português na constante (`BarreiraDoEscritorioInativoNaRota::ROTULO`) e a página traduz pela chave.
 * O português do catálogo tem de ser o mesmo da constante, senão a tela muda. No T2, o mesmo vale para as recusas da
 * `RegraDosPrimarios` que o edit-role e a remoção do colaborador mostram (`RegraDosPrimarios::CHAVE_DA_RECUSA`).
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

    #[TestDox('Toda recusa da regra dos primários tem chave no catálogo, com o mesmo texto em português')]
    public function testRecusasDaRegraDosPrimariosNoCatalogo(): void
    {
        self::bootKernel();
        $tradutor = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $tradutor);

        $recusas = array_filter(
            (new \ReflectionClass(RegraDosPrimarios::class))->getConstants(),
            static fn (string $nome): bool => str_starts_with($nome, 'RECUSA_'),
            ARRAY_FILTER_USE_KEY,
        );
        self::assertNotEmpty($recusas);
        self::assertCount(count($recusas), RegraDosPrimarios::CHAVE_DA_RECUSA, 'uma chave por recusa');

        foreach ($recusas as $nome => $texto) {
            self::assertArrayHasKey($texto, RegraDosPrimarios::CHAVE_DA_RECUSA, $nome . ' sem chave no catálogo');
            self::assertSame($texto, $tradutor->trans(RegraDosPrimarios::CHAVE_DA_RECUSA[$texto], [], null, 'pt_BR'), $nome);
        }
    }
}
