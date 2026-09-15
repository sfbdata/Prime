<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Contrato;

use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * O dublê em memória cumprindo o MESMO contrato do backend real.
 *
 * Não é zelo: é o que autoriza os testes das fatias seguintes a rodar contra ele. Um dublê que
 * divergisse do contrato daria verde onde o disco daria vermelho.
 */
#[CoversClass(ArmazenamentoEmMemoria::class)]
final class ArmazenamentoEmMemoriaContratoTest extends ArmazenamentoContratoTestCase
{
    private ArmazenamentoEmMemoria $backend;

    protected function setUp(): void
    {
        $this->backend = new ArmazenamentoEmMemoria();
    }

    protected function backend(): ArmazenamentoDeArquivos
    {
        return $this->backend;
    }
}
