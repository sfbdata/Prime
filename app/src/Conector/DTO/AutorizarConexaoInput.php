<?php

declare(strict_types=1);

namespace App\Conector\DTO;

/**
 * O que a tela de autorização do conector manda no POST (`docs/specs/mcp-remoto-oauth.md`, 9.4): o id do pedido
 * guardado na sessão, o escritório escolhido e a caixa de gravação. Nenhum parâmetro OAuth vem daqui: eles moram na
 * sessão ({@see PedidoDeAutorizacao}). O escritório é só o que a pessoa disse: quem decide se ela pode conectá-lo é o
 * {@see \App\Conector\UseCase\AutorizarConexaoUseCase}, no servidor.
 *
 * Sem `readonly`: o Symfony Form escreve nos campos.
 */
final class AutorizarConexaoInput
{
    public ?string $pedido = null;

    public ?int $tenantId = null;

    public bool $escrita = false;
}
