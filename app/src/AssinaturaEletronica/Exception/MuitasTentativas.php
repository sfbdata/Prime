<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Exception;

/**
 * Limite de tentativas da tela pública estourado (limitador, teto diário ou bloqueio do signatário): vira 429 com
 * "Muitas tentativas. Aguarde alguns minutos e tente de novo." (contrato da etapa 2, seção 5.2).
 */
final class MuitasTentativas extends \RuntimeException
{
    public const CHAVE = 'assinatura_eletronica.erro.muitas_tentativas';

    public function __construct()
    {
        parent::__construct(self::CHAVE);
    }
}
