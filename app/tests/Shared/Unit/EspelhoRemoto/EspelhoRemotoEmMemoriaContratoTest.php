<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\EspelhoRemoto;

use App\Shared\EspelhoRemoto\EspelhoRemoto;
use App\Tests\Shared\Doubles\EspelhoRemotoEmMemoria;
use PHPUnit\Framework\Attributes\CoversNothing;

/** O dublê cumpre o mesmo contrato do adaptador real — é o que o torna utilizável na P2.3. */
#[CoversNothing]
final class EspelhoRemotoEmMemoriaContratoTest extends EspelhoRemotoContratoTestCase
{
    private EspelhoRemotoEmMemoria $espelho;

    protected function setUp(): void
    {
        $this->espelho = new EspelhoRemotoEmMemoria();
    }

    protected function espelho(): EspelhoRemoto
    {
        return $this->espelho;
    }
}
