<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Exception;

/**
 * A selfie guardada não abre (contrato da etapa 7, seção 5.3): arquivo que sumiu do disco, cabeçalho ou versão da chave
 * que não é a do cofre, dados associados de outro dono, byte trocado ou sha256 diferente do gravado na assinatura. Quem
 * mostrar a selfie (etapa 3) trata como erro do servidor, sem dizer qual foi.
 */
final class SelfieIlegivel extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('A selfie guardada não pôde ser aberta.');
    }
}
