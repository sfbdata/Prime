<?php

declare(strict_types=1);

namespace App\Conector\Service;

use App\Conector\Entity\ClienteDoConector;
use App\Conector\Exception\ClienteRecusado;

/**
 * Quem diz se um app de I.A. pode pedir acesso, e com qual endereço de retorno (`docs/specs/mcp-remoto-oauth.md`, 3.4).
 * No piloto, só por CIMD ({@see RegistroDeClientesPorCimd}).
 *
 * {@see resolver()} devolve o cliente gravado (criado ou atualizado se o documento foi buscado agora) só quando o
 * `client_id` é aceito E o `redirect_uri` está, byte a byte, no documento do cliente E na lista exata do servidor.
 * Qualquer outra coisa lança {@see ClienteRecusado}, que no `/oauth/authorize` nunca redireciona.
 */
interface RegistroDeClientes
{
    /** @throws ClienteRecusado */
    public function resolver(string $clientId, string $redirectUri): ClienteDoConector;
}
