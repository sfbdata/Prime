<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\EspelhoRemoto;

use App\Shared\EspelhoRemoto\EspelhoRemoto;
use App\Shared\EspelhoRemoto\R2\ClienteR2;
use App\Shared\EspelhoRemoto\R2\EspelhoRemotoR2;
use App\Tests\Shared\Doubles\S3Simulado;
use PHPUnit\Framework\Attributes\CoversClass;

/** O adaptador R2 de verdade (async-aws), falando com o S3 simulado — sem rede. */
#[CoversClass(EspelhoRemotoR2::class)]
#[CoversClass(ClienteR2::class)]
final class EspelhoRemotoR2ContratoTest extends EspelhoRemotoContratoTestCase
{
    private EspelhoRemotoR2 $espelho;

    protected function setUp(): void
    {
        $s3            = new S3Simulado();
        $this->espelho = new EspelhoRemotoR2(ClienteR2::criar($s3->endpoint, $s3->chaveDeAcesso, 'SEGREDO-DE-TESTE', $s3->cliente()), $s3->bucket);
    }

    protected function espelho(): EspelhoRemoto
    {
        return $this->espelho;
    }
}
