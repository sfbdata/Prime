<?php

declare(strict_types=1);

namespace App\Conector\Service;

use App\Conector\Entity\ClienteDoConector;
use App\Conector\Exception\ClienteRecusado;
use App\Conector\Repository\ClienteDoConectorRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Registro do cliente por CIMD, o único caminho do piloto (`docs/specs/mcp-remoto-oauth.md`, 3.4): o `client_id` é a
 * URL do documento de metadados do app.
 *
 * ESQUELETO do commit 0 do PR 3: só o caminho do cache válido (cliente gravado, ativo, metadados dentro do prazo e
 * `redirect_uri` no documento gravado). Todo o resto recusa. A busca segura do documento, os hosts permitidos em
 * configuração, a lista exata de `redirect_uri` e a gravação do cliente entram no bloco C do PR 3, NESTA classe, sem
 * mudar a interface {@see RegistroDeClientes}.
 */
#[AsAlias(RegistroDeClientes::class)]
final class RegistroDeClientesPorCimd implements RegistroDeClientes
{
    public function __construct(
        private readonly ClienteDoConectorRepository $clientes,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function resolver(string $clientId, string $redirectUri): ClienteDoConector
    {
        $host = ClienteRecusado::hostDe($clientId);
        $cliente = $this->clientes->buscarPorIdentificador($clientId);
        if (!$cliente instanceof ClienteDoConector) {
            throw new ClienteRecusado(ClienteRecusado::MOTIVO_DOCUMENTO_INDISPONIVEL, $host);
        }
        if (!$cliente->isAtivo()) {
            throw new ClienteRecusado(ClienteRecusado::MOTIVO_CLIENTE_INATIVO, $host);
        }

        $validoAte = $cliente->getMetadadosValidosAte();
        if ($validoAte === null || $validoAte <= $this->relogio->now()) {
            // Bloco C: buscar o documento de novo aqui, em vez de recusar.
            throw new ClienteRecusado(ClienteRecusado::MOTIVO_DOCUMENTO_INDISPONIVEL, $host);
        }

        // Bloco C: conferir também a lista exata de `redirect_uri` do servidor (3.4) e o host na configuração.
        if (!\in_array($redirectUri, $cliente->getRedirectUris(), true)) {
            throw new ClienteRecusado(ClienteRecusado::MOTIVO_REDIRECT_NAO_PERMITIDO, $host);
        }

        return $cliente;
    }
}
