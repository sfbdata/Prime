<?php

declare(strict_types=1);

namespace App\Contribua\Banco;

/**
 * Banco que também emite boleto de apoio (chave `bol` do desenho 08). Só a Cora, por enquanto: é um
 * acréscimo ao lado de BancoPix, que Inter, BB e "Outro" continuam implementando sem mudança.
 */
interface BancoBoleto
{
    /** Mínimo do boleto da Cora (abaixo disso a API responde 400). */
    public const MINIMO_CENTAVOS = 500;

    /**
     * @throws FalhaNoBanco
     * @throws \DomainException valor abaixo do mínimo (nem chega ao banco)
     */
    public function emitirBoleto(PedidoDeBoleto $pedido): BoletoEmitido;
}
