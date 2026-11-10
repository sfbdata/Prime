<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Integration;

use App\AssinaturaEletronica\Service\Selo\EscolhaDoSeladorDePdf;
use App\AssinaturaEletronica\Service\Selo\HttpSeladorDePdf;
use App\AssinaturaEletronica\Service\Selo\SeladorDePdf;
use App\AssinaturaEletronica\Service\Selo\SeladorDePdfNaoConfigurado;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A fiação do selo no contêiner (spec assinatura-documentos-etapa-8, seção 2): o `SeladorDePdf` sai da fábrica
 * {@see EscolhaDoSeladorDePdf} com a URL e o token do ambiente, e o contêiner sobe mesmo sem as duas variáveis. Prova
 * pelo serviço resolvido, não pelo texto do `services.yaml`.
 */
#[CoversClass(EscolhaDoSeladorDePdf::class)]
final class FiacaoDoSeladorDePdfTest extends KernelTestCase
{
    #[TestDox('o SeladorDePdf do contêiner é o que a fábrica escolhe com a URL e o token do ambiente')]
    public function testSeladorDoConteiner(): void
    {
        self::bootKernel();

        $selador = static::getContainer()->get(SeladorDePdf::class);

        $configurado = self::variavel('ASSINATURA_VALIDADOR_URL') !== '' && self::variavel('ASSINATURA_VALIDADOR_TOKEN') !== '';
        self::assertInstanceOf($configurado ? HttpSeladorDePdf::class : SeladorDePdfNaoConfigurado::class, $selador);
    }

    private static function variavel(string $nome): string
    {
        $valor = $_SERVER[$nome] ?? $_ENV[$nome] ?? '';

        return \is_string($valor) ? trim($valor) : '';
    }
}
