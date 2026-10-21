<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Integration;

use App\Tenant\Service\HierarquiaDePerfis;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Idioma do sistema (spec docs/specs/idioma-do-sistema.md): as recusas da hierarquia dos perfis continuam como texto
 * em português nas constantes (é o que as outras telas e os testes comparam) e a tela dos Perfis traduz pela chave
 * (`HierarquiaDePerfis::CHAVE_DA_RECUSA`). O português do catálogo tem de ser o mesmo da constante, senão a tela muda.
 */
#[CoversNothing]
final class CatalogoDasRecusasTest extends KernelTestCase
{
    #[TestDox('Toda recusa da hierarquia tem chave no catálogo, com o mesmo texto em português')]
    public function testRecusasNoCatalogo(): void
    {
        self::bootKernel();
        $tradutor = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $tradutor);

        $recusas = array_filter(
            (new \ReflectionClass(HierarquiaDePerfis::class))->getConstants(),
            static fn (string $nome): bool => str_starts_with($nome, 'RECUSA_'),
            ARRAY_FILTER_USE_KEY,
        );
        self::assertNotEmpty($recusas);

        foreach ($recusas as $nome => $texto) {
            self::assertArrayHasKey($texto, HierarquiaDePerfis::CHAVE_DA_RECUSA, $nome . ' sem chave no catálogo');
            self::assertSame($texto, $tradutor->trans(HierarquiaDePerfis::CHAVE_DA_RECUSA[$texto], [], null, 'pt_BR'), $nome);
        }
    }
}
