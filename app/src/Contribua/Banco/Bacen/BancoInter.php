<?php

declare(strict_types=1);

namespace App\Contribua\Banco\Bacen;

use App\Contribua\Entity\IntegracaoBancaria;

/**
 * Banco Inter (077), API Pix v2 do Inter Empresas (developers.inter.co/references/pix): token em
 * `/oauth/v2/token` com `client_id` e `client_secret` no corpo (form) e mTLS com o `.crt` e o `.key` da
 * integração; API em `/pix/v2`. Com a conta corrente preenchida, toda chamada à API Pix (não o token) leva
 * o cabeçalho `x-conta-corrente` (só números, com o dígito e sem zeros à esquerda): a documentação o exige
 * só quando a aplicação está associada a mais de uma conta.
 *
 * SUPOSIÇÃO a validar com a credencial real: as URLs de sandbox (`cdpj-sandbox.partners.uatinter.co`, com os
 * mesmos caminhos da produção) e o envio das credenciais no corpo, como mostram os exemplos públicos do Inter.
 */
final class BancoInter extends BancoPixBacen
{
    public const TOKEN_PRODUCAO = 'https://cdpj.partners.bancointer.com.br/oauth/v2/token';
    public const BASE_PRODUCAO = 'https://cdpj.partners.bancointer.com.br/pix/v2';
    public const TOKEN_SANDBOX = 'https://cdpj-sandbox.partners.uatinter.co/oauth/v2/token';
    public const BASE_SANDBOX = 'https://cdpj-sandbox.partners.uatinter.co/pix/v2';

    public function codigo(): string
    {
        return 'inter';
    }

    protected function nome(): string
    {
        return 'o Banco Inter';
    }

    protected function urlDoToken(IntegracaoBancaria $i): string
    {
        return 'producao' === $i->getAmbiente() ? self::TOKEN_PRODUCAO : self::TOKEN_SANDBOX;
    }

    protected function urlBase(IntegracaoBancaria $i): string
    {
        return 'producao' === $i->getAmbiente() ? self::BASE_PRODUCAO : self::BASE_SANDBOX;
    }

    protected function tokenPorBasic(): bool
    {
        return false;
    }

    public function usaContaCorrente(): bool
    {
        return true;
    }

    protected function cabecalhosDaApi(IntegracaoBancaria $i): array
    {
        $conta = (string) $i->getContaCorrente();

        return '' === $conta ? [] : ['x-conta-corrente' => $conta];
    }
}
