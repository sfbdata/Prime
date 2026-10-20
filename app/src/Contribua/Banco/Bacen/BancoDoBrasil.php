<?php

declare(strict_types=1);

namespace App\Contribua\Banco\Bacen;

use App\Contribua\Entity\IntegracaoBancaria;

/**
 * Banco do Brasil (001), API Pix v2 do Portal do Desenvolvedor BB (developers.bb.com.br, "API Pix"): token
 * OAuth2 com `Authorization: Basic client_id:client_secret`; toda chamada à API leva a app key da aplicação
 * como parâmetro de consulta: `gw-dev-app-key` em homologação e `gw-app-key` em produção. mTLS com o
 * certificado A1 da empresa cadastrado no portal (em geral um `.pfx` com senha, D7).
 *
 * SUPOSIÇÃO a validar com a credencial real: a base de produção. A spec usa `api.bb.com.br/pix/v2`; a
 * documentação do BB também cita `api-pix.bb.com.br/pix/v2` como a base com mTLS obrigatório. Se o teste de
 * conexão em produção recusar a consulta, é a primeira coisa a trocar (BASE_PRODUCAO).
 */
final class BancoDoBrasil extends BancoPixBacen
{
    public const TOKEN_PRODUCAO = 'https://oauth.bb.com.br/oauth/token';
    public const TOKEN_HOMOLOGACAO = 'https://oauth.hm.bb.com.br/oauth/token';
    public const BASE_PRODUCAO = 'https://api.bb.com.br/pix/v2';
    public const BASE_HOMOLOGACAO = 'https://api.hm.bb.com.br/pix/v2';

    public function codigo(): string
    {
        return 'bb';
    }

    protected function nome(): string
    {
        return 'o Banco do Brasil';
    }

    protected function urlDoToken(IntegracaoBancaria $i): string
    {
        return 'producao' === $i->getAmbiente() ? self::TOKEN_PRODUCAO : self::TOKEN_HOMOLOGACAO;
    }

    protected function urlBase(IntegracaoBancaria $i): string
    {
        return 'producao' === $i->getAmbiente() ? self::BASE_PRODUCAO : self::BASE_HOMOLOGACAO;
    }

    protected function parametrosDaApi(IntegracaoBancaria $i, string $appKey): array
    {
        return ['producao' === $i->getAmbiente() ? 'gw-app-key' : 'gw-dev-app-key' => $appKey];
    }

    protected function exigeAppKey(): bool
    {
        return true;
    }
}
