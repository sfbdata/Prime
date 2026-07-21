<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\EditarObrigacaoInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EditarObrigacaoInput::class)]
final class EditarObrigacaoInputTaxaTest extends TestCase
{
    #[Test]
    public function montaEntradaTaxasComOsModosEValores(): void
    {
        $input = new EditarObrigacaoInput();
        $input->modoJuros = 'reais';
        $input->jurosReais = 1360;
        $input->modoMulta = 'percent';
        $input->multaBp = 200;

        $entrada = $input->entradaTaxas();

        self::assertSame('reais', $entrada->modoJuros);
        self::assertSame(1360, $entrada->jurosReais);
        self::assertSame('percent', $entrada->modoMulta);
        self::assertSame(200, $entrada->multaBp);
        self::assertSame('herda', $entrada->modoCorrecao);
    }
}
