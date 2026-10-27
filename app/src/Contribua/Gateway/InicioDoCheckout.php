<?php

declare(strict_types=1);

namespace App\Contribua\Gateway;

/**
 * Como o checkout continua depois que o BlueJus pediu ao gateway.
 *
 *  - REDIRECIONAR: página hospedada do gateway (Asaas Checkout, link de pagamento). O cartão nunca passa
 *    pelo BlueJus.
 *  - FORMULARIO: o formulário de cartão do desenho, tokenizado NO NAVEGADOR com a chave pública e o SDK
 *    oficial do gateway; o servidor só recebe o token (`GatewayDeAssinatura::assinarComToken`).
 */
final readonly class InicioDoCheckout
{
    public const REDIRECIONAR = 'redirecionar';
    public const FORMULARIO = 'formulario';

    private function __construct(
        public string $modo,
        public ?string $urlDeRedirecionamento,
        public ?string $chavePublica,
        /** Id do checkout/sessão/assinatura no gateway, quando ele já devolve na criação. */
        public ?string $idNoGateway,
    ) {
    }

    public static function redirecionar(string $url, ?string $idNoGateway = null): self
    {
        if (!str_starts_with($url, 'https://')) {
            throw new \LogicException('O checkout hospedado precisa ser https.');
        }

        return new self(self::REDIRECIONAR, $url, null, $idNoGateway);
    }

    public static function formulario(string $chavePublica): self
    {
        return new self(self::FORMULARIO, null, $chavePublica, null);
    }
}
