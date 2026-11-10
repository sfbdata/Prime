<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\DTO;

/** O que a assinatura Reforçada tem a mais que a Simples, já conferido (contrato da etapa 7, seção 4.2). */
final readonly class ReforcoDaAssinatura
{
    public function __construct(
        public SelfieRegravada $selfie,
        public LocalizacaoDoSignatario $localizacao,
    ) {
    }
}
