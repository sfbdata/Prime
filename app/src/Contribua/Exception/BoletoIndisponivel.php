<?php

declare(strict_types=1);

namespace App\Contribua\Exception;

/**
 * Não há boleto para quem pediu: chave "Boleto" desligada, integração não conectada, banco que não emite
 * boleto (só a Cora emite) ou ambiente de testes fora do escritório do Master. A tela nem mostra o botão.
 */
final class BoletoIndisponivel extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Boleto indisponível.');
    }
}
