<?php

declare(strict_types=1);

namespace App\Tests\ServiceDesk\Unit;

use App\Contribua\Service\CodigoPixEstatico;
use App\ServiceDesk\Enum\FormaDeRecarga;
use App\ServiceDesk\Pagamento\PedidoDeRecarga;
use App\ServiceDesk\Pagamento\RecargaManual;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecargaManual::class)]
final class RecargaManualTest extends TestCase
{
    public function testPixDevolveCopiaEColaValidoComAReferenciaComoTxid(): void
    {
        $instrucao = (new RecargaManual())->iniciar(new PedidoDeRecarga(1, 'BJSD0123456789AB', 5000, FormaDeRecarga::Pix, 'Recarga'));

        self::assertNotNull($instrucao->copiaECola);
        self::assertTrue(CodigoPixEstatico::valido($instrucao->copiaECola));
        self::assertStringContainsString('BJSD0123456789AB', $instrucao->copiaECola);
        self::assertStringContainsString('50.00', $instrucao->copiaECola);
    }

    public function testCartaoNaoTemCopiaECola(): void
    {
        $instrucao = (new RecargaManual())->iniciar(new PedidoDeRecarga(1, 'BJSD0123456789AB', 5000, FormaDeRecarga::Cartao, 'Recarga'));

        self::assertNull($instrucao->copiaECola);
    }
}
