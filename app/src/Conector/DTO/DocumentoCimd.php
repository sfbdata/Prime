<?php

declare(strict_types=1);

namespace App\Conector\DTO;

use App\Conector\Entity\ClienteDoConector;
use App\Conector\Exception\ClienteRecusado;

/**
 * O documento de metadados do cliente (CIMD) já validado (`docs/specs/mcp-remoto-oauth.md`, 3.4): o `client_id` igual,
 * byte a byte, à URL buscada; `client_name` e `redirect_uris` presentes e do tipo certo; `token_endpoint_auth_method`, se
 * vier, só `none` (o metadado do servidor anuncia só ele). Leva também até quando vale pelo cache HTTP.
 *
 * Qualquer desvio lança {@see ClienteRecusado} com `documento_invalido`. O conteúdo do documento nunca vai para o log.
 */
final class DocumentoCimd
{
    private const PROFUNDIDADE_DO_JSON = 16;
    private const NOME_MAXIMO = 255;

    /**
     * @param list<string> $redirectUris
     */
    private function __construct(
        public readonly string $clientId,
        public readonly string $clientName,
        public readonly array $redirectUris,
        public readonly \DateTimeImmutable $validoAte,
    ) {
    }

    /** @throws ClienteRecusado */
    public static function doCorpo(string $corpo, string $urlBuscada, \DateTimeImmutable $validoAte): self
    {
        try {
            $dados = json_decode($corpo, true, self::PROFUNDIDADE_DO_JSON, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw self::invalido($urlBuscada, $e);
        }
        if (!\is_array($dados) || array_is_list($dados)) {
            throw self::invalido($urlBuscada);
        }

        if (($dados['client_id'] ?? null) !== $urlBuscada) {
            throw self::invalido($urlBuscada);
        }
        if (\array_key_exists('token_endpoint_auth_method', $dados) && $dados['token_endpoint_auth_method'] !== 'none') {
            throw self::invalido($urlBuscada);
        }

        return new self($urlBuscada, self::nome($dados['client_name'] ?? null, $urlBuscada), self::redirects($dados['redirect_uris'] ?? null, $urlBuscada), $validoAte);
    }

    private static function nome(mixed $nome, string $url): string
    {
        if (!\is_string($nome) || trim($nome) === '' || mb_strlen(trim($nome)) > self::NOME_MAXIMO) {
            throw self::invalido($url);
        }

        return trim($nome);
    }

    /** @return list<string> */
    private static function redirects(mixed $redirects, string $url): array
    {
        if (!\is_array($redirects) || $redirects === [] || !array_is_list($redirects)) {
            throw self::invalido($url);
        }
        foreach ($redirects as $redirect) {
            if (!\is_string($redirect) || trim($redirect) === '' || \strlen($redirect) > ClienteDoConector::IDENTIFICADOR_MAXIMO) {
                throw self::invalido($url);
            }
        }

        return $redirects;
    }

    private static function invalido(string $url, ?\Throwable $causa = null): ClienteRecusado
    {
        return new ClienteRecusado(ClienteRecusado::MOTIVO_DOCUMENTO_INVALIDO, ClienteRecusado::hostDe($url), $causa);
    }
}
