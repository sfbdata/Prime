<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\DTO;

use Symfony\Component\HttpFoundation\Request;

/**
 * A evidência de quem agiu na tela pública (contrato da etapa 2, seção 4.3): IP pelo `getClientIp()` (o nginx de
 * produção força o `X-Forwarded-For` para o cliente), porta de origem (`REMOTE_PORT`, que vai em `dados.porta` porque a
 * coluna `ip` tem 45 caracteres) e navegador cortado em 500. A hora é a do relógio do servidor, posta pela trilha.
 */
final readonly class EvidenciaDaRequisicao
{
    public const NAVEGADOR_MAXIMO = 500;
    public const IP_MAXIMO = 45;

    public function __construct(
        public ?string $ip,
        public ?int $porta,
        public ?string $navegador,
    ) {
    }

    public static function da(Request $request): self
    {
        $ip = $request->getClientIp();
        $porta = filter_var($request->server->get('REMOTE_PORT'), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $navegador = trim((string) $request->headers->get('User-Agent', ''));

        return new self(
            $ip !== null && $ip !== '' ? mb_substr($ip, 0, self::IP_MAXIMO) : null,
            \is_int($porta) ? $porta : null,
            $navegador !== '' ? mb_substr($navegador, 0, self::NAVEGADOR_MAXIMO) : null,
        );
    }
}
