<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\Selo;

/**
 * Sem `ASSINATURA_VALIDADOR_URL` ou sem `ASSINATURA_VALIDADOR_TOKEN` (vazios = não configurado, etapa 6, seção 2): o
 * PDF final é guardado sem o selo e nada vai para a trilha (E8-4). Nenhum pedido sai.
 */
final class SeladorDePdfNaoConfigurado implements SeladorDePdf
{
    public function selar(string $pdf): ?string
    {
        return null;
    }
}
