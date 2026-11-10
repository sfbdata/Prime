<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\ConferenciaDePdf;

/**
 * Interna do motor: a estrutura passou de um teto de `LimitesDaConferencia` (profundidade, elementos, certificados).
 */
final class LimiteExcedido extends FormatoNaoReconhecido
{
    public const MOTIVO_DO_LIMITE = 'A assinatura passa dos limites de conferência';

    public function motivo(): string
    {
        return self::MOTIVO_DO_LIMITE;
    }
}
