<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Inteligencia\DTO\PedidoDeLinguagem;
use App\Inteligencia\Exception\ProvedorIndisponivelException;
use App\Inteligencia\Service\ProvedorNaoConfigurado;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A regra de ouro: sem provedor, exceção — nunca texto inventado.
 */
#[CoversClass(ProvedorNaoConfigurado::class)]
final class ProvedorNaoConfiguradoTest extends TestCase
{
    #[TestDox('não está configurado e se chama nao_configurado')]
    public function testNaoEstaConfigurado(): void
    {
        $provedor = new ProvedorNaoConfigurado();

        self::assertFalse($provedor->estaConfigurado());
        self::assertSame('nao_configurado', $provedor->nome());
    }

    #[TestDox('completar() lança ProvedorIndisponivelException em vez de responder')]
    public function testCompletarLancaIndisponivel(): void
    {
        $provedor = new ProvedorNaoConfigurado();
        $pedido = new PedidoDeLinguagem('sistema', [['papel' => 'usuario', 'conteudo' => 'oi']], 10, 0.0, false, 'teste');

        $this->expectException(ProvedorIndisponivelException::class);
        $this->expectExceptionMessage('não configurado');

        $provedor->completar($pedido);
    }
}
