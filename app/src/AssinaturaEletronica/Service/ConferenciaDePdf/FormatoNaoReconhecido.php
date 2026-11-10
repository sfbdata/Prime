<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\ConferenciaDePdf;

/**
 * Interna do motor: a estrutura lida não é a esperada. Nunca sai do motor; quem chama recebe o motivo no resultado.
 */
class FormatoNaoReconhecido extends \RuntimeException
{
    public const MOTIVO = 'Formato de assinatura não reconhecido';

    public function motivo(): string
    {
        return self::MOTIVO;
    }
}
