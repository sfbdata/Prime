<?php

declare(strict_types=1);

namespace App\Conector\Exception;

/**
 * O app de I.A. que pediu acesso não foi aceito pelo registro de clientes (CIMD, `docs/specs/mcp-remoto-oauth.md`,
 * 3.4 e 9.5): `client_id` malformado ou de host fora da lista, documento indisponível ou inválido, `redirect_uri` fora
 * do documento ou da lista exata, cliente desativado, limite de buscas.
 *
 * No `/oauth/authorize` esta recusa NUNCA redireciona (RFC 6749 4.1.2.1: com cliente ou redirect inválido, o servidor
 * não pode confiar no `redirect_uri`): a pessoa vê uma página de erro do BlueJus. No log `mcp` vai só o
 * {@see $motivo} e o {@see $host} do `client_id`, nunca a URL inteira nem o redirect.
 */
final class ClienteRecusado extends \RuntimeException
{
    public const MOTIVO_CLIENT_ID_INVALIDO = 'client_id_invalido';
    public const MOTIVO_HOST_NAO_PERMITIDO = 'host_nao_permitido';
    public const MOTIVO_DOCUMENTO_INDISPONIVEL = 'documento_indisponivel';
    public const MOTIVO_DOCUMENTO_INVALIDO = 'documento_invalido';
    public const MOTIVO_REDIRECT_NAO_PERMITIDO = 'redirect_nao_permitido';
    public const MOTIVO_CLIENTE_INATIVO = 'cliente_inativo';
    public const MOTIVO_LIMITE_DE_BUSCA = 'limite_de_busca';

    public const MOTIVOS = [
        self::MOTIVO_CLIENT_ID_INVALIDO,
        self::MOTIVO_HOST_NAO_PERMITIDO,
        self::MOTIVO_DOCUMENTO_INDISPONIVEL,
        self::MOTIVO_DOCUMENTO_INVALIDO,
        self::MOTIVO_REDIRECT_NAO_PERMITIDO,
        self::MOTIVO_CLIENTE_INATIVO,
        self::MOTIVO_LIMITE_DE_BUSCA,
    ];

    /** O texto que a página de erro mostra à pessoa: o mesmo para todo motivo. */
    public const MENSAGEM = 'O aplicativo que pediu acesso não foi reconhecido pelo BlueJus.';

    /**
     * @param string      $motivo um de {@see MOTIVOS}
     * @param string|null $host   o host do `client_id` (minúsculo), ou nulo quando ele nem é uma URL
     */
    public function __construct(
        public readonly string $motivo,
        public readonly ?string $host,
        ?\Throwable $causa = null,
    ) {
        if (!\in_array($motivo, self::MOTIVOS, true)) {
            throw new \InvalidArgumentException('Motivo de recusa do cliente desconhecido.');
        }

        parent::__construct(self::MENSAGEM, 0, $causa);
    }

    /** O host do `client_id` em minúsculas, ou nulo quando não é uma URL com host. Para o log e a recusa. */
    public static function hostDe(string $clientId): ?string
    {
        $host = parse_url($clientId, \PHP_URL_HOST);

        return \is_string($host) && $host !== '' ? strtolower($host) : null;
    }
}
