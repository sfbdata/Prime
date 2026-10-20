<?php

declare(strict_types=1);

namespace App\Contribua\Banco\Bacen;

use App\Contribua\Entity\IntegracaoBancaria;

/**
 * "Outro (API PIX padrão BACEN)": qualquer PSP que siga a API Pix do BACEN. A URL base da API Pix e a URL do
 * token vêm da configuração (fornecidas pelo PSP), as mesmas nos dois ambientes; o token é pedido com
 * `Authorization: Basic`, o método que o OAuth2 manda todo servidor aceitar (RFC 6749 §2.3.1).
 */
final class BancoPixPadrao extends BancoPixBacen
{
    public function codigo(): string
    {
        return 'outro';
    }

    protected function nome(): string
    {
        return 'o banco';
    }

    protected function urlDoToken(IntegracaoBancaria $i): string
    {
        return (string) $i->getUrlToken();
    }

    protected function urlBase(IntegracaoBancaria $i): string
    {
        return (string) $i->getUrlBase();
    }

    protected function exigeEnderecos(): bool
    {
        return true;
    }
}
