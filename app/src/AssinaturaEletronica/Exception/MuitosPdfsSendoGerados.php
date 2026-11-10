<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Exception;

/**
 * "Baixar PDF com selo" sem vaga: as duas gerações da web ocupadas (VagasDeGeracaoDoPdf) ou o limitador da geração
 * estourado. Vira 429 com "Muitos PDFs sendo gerados agora. Tente de novo em instantes.".
 */
final class MuitosPdfsSendoGerados extends \RuntimeException
{
    public const CHAVE = 'assinatura_eletronica_tela.js.pdf.muitos';

    public function __construct()
    {
        parent::__construct(self::CHAVE);
    }
}
