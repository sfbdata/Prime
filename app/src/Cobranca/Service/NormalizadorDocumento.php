<?php

declare(strict_types=1);

namespace App\Cobranca\Service;

/**
 * Normalização de documentos (CPF/CNPJ) no domínio de cobranças: reduz o valor a apenas dígitos,
 * para que a deduplicação intra-tenant (invariável 24) independa da formatação — "123.456.789-01"
 * e "12345678901" são o mesmo documento. Utilitário puro e sem estado; é o ponto único que o
 * cadastro, a sugestão de duplicadas e (quando desbloqueada) a importação da Etapa 7 reusam.
 */
final class NormalizadorDocumento
{
    /**
     * Retorna apenas os dígitos do documento; null quando ausente ou sem nenhum dígito (nada a
     * comparar). Espelha, no PHP, o `regexp_replace(..., '\D', '', 'g')` usado no banco.
     */
    public static function apenasDigitos(?string $documento): ?string
    {
        if ($documento === null) {
            return null;
        }

        $digitos = preg_replace('/\D/', '', $documento);

        return $digitos !== '' ? $digitos : null;
    }
}
