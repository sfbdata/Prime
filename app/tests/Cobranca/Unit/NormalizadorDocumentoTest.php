<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Service\NormalizadorDocumento;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NormalizadorDocumento::class)]
final class NormalizadorDocumentoTest extends TestCase
{
    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function documentos(): array
    {
        return [
            'cpf formatado' => ['529.982.247-25', '52998224725'],
            'cpf já em dígitos' => ['52998224725', '52998224725'],
            'cnpj formatado' => ['11.444.777/0001-61', '11444777000161'],
            'com espaços ao redor' => ['  123.456.789-01  ', '12345678901'],
            'null permanece null' => [null, null],
            'string vazia vira null' => ['', null],
            'só pontuação vira null' => ['.-/ ', null],
            'letras são descartadas' => ['CPF: 12a34', '1234'],
        ];
    }

    #[Test]
    #[DataProvider('documentos')]
    public function reduzDocumentoApenasAosDigitos(?string $entrada, ?string $esperado): void
    {
        self::assertSame($esperado, NormalizadorDocumento::apenasDigitos($entrada));
    }
}
