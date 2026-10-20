<?php

declare(strict_types=1);

namespace App\Shared\Email\Descadastro;

/**
 * O que um link de descadastro assinado carrega, já conferido: o escopo (id do escritório, ou null =
 * plataforma), o endereço e o assunto.
 */
final readonly class PedidoDeDescadastro
{
    public function __construct(
        public ?int $tenantId,
        public string $email,
        public string $assunto,
    ) {
    }

    /** O endereço para mostrar na tela sem expô-lo inteiro: "j***@gmail.com". */
    public function emailMascarado(): string
    {
        $arroba = mb_strrpos($this->email, '@');
        if ($arroba === false || $arroba === 0) {
            return '***';
        }

        return mb_substr($this->email, 0, 1) . '***' . mb_substr($this->email, $arroba);
    }
}
