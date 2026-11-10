<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Integration;

use App\AssinaturaEletronica\Service\Cadeia\CadeiaIndisponivel;
use App\AssinaturaEletronica\Service\Cadeia\HttpValidadorDeCadeia;
use App\AssinaturaEletronica\Service\Cadeia\ValidadorDeCadeia;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * O alias do validador de cadeia (etapa 6, bloco 6B): no container é o cliente HTTP, e sem `ASSINATURA_VALIDADOR_URL`
 * e `ASSINATURA_VALIDADOR_TOKEN` ele fica não configurado (os testes nunca chamam o validador de verdade).
 */
#[CoversNothing]
#[Group('assinatura')]
final class ValidadorDeCadeiaNoContainerTest extends KernelTestCase
{
    #[TestDox('ValidadorDeCadeia é o HttpValidadorDeCadeia; sem as variáveis, não configurado e indisponível')]
    public function testAliasSemConfiguracao(): void
    {
        $validador = self::getContainer()->get(ValidadorDeCadeia::class);

        self::assertInstanceOf(HttpValidadorDeCadeia::class, $validador);
        self::assertFalse($validador->configurado());
        $this->expectException(CadeiaIndisponivel::class);
        $validador->conferir('%PDF-1.7', ValidadorDeCadeia::ANCORA_ICP);
    }
}
