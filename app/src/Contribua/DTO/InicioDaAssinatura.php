<?php

declare(strict_types=1);

namespace App\Contribua\DTO;

/** O que o checkout da tela faz depois do "Assinar": ir à página do gateway ou mostrar o formulário de cartão. */
final readonly class InicioDaAssinatura
{
    public function __construct(
        public int $pedidoId,
        /** `InicioDoCheckout::REDIRECIONAR` ou `InicioDoCheckout::FORMULARIO`. */
        public string $modo,
        public ?string $url,
        public ?string $chavePublica,
        public string $gateway,
    ) {
    }

    /** @return array<string, int|string|null> */
    public function paraJson(): array
    {
        return ['pedido' => $this->pedidoId, 'modo' => $this->modo, 'url' => $this->url, 'chavePublica' => $this->chavePublica, 'gateway' => $this->gateway];
    }
}
