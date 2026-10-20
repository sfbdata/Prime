<?php

declare(strict_types=1);

namespace App\Contribua\Banco;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Banco que recebe o apoio por PIX com QR dinâmico (desenho 08, "Integração bancária"): a Cora e, atrás
 * desta mesma interface, Inter, Banco do Brasil e "Outro" (API PIX do BACEN, C4b/C4c). Toda implementação
 * entra sozinha em BancosPix (tag pelo nome da interface).
 */
#[AutoconfigureTag]
interface BancoPix
{
    /** Código do banco no seletor e no endereço do webhook (`/api/webhooks/<codigo>/pix`). */
    public function codigo(): string;

    /** Conecta no ambiente configurado (pede o token) e diz se deu certo. */
    public function testarConexao(): ResultadoDoTeste;

    /** @throws FalhaNoBanco */
    public function criarCobranca(PedidoDeCobrancaPix $pedido): CobrancaPix;

    /** @throws FalhaNoBanco */
    public function consultar(string $idNoBanco): SituacaoDaCobranca;
}
