<?php

declare(strict_types=1);

namespace App\Cobranca\Service;

/**
 * Normalização de nome de pessoa para deduplicação na importação (decisão A do mapeamento TOPLIFE):
 * caixa alta, espaços colapsados e aparados. Assim "Maria  Silva " e "MARIA SILVA" são o mesmo nome.
 * Utilitário puro, sem estado. A comparação é SEMPRE dentro do mesmo Objeto/Carteira (nunca global,
 * nunca entre tenants) — este helper só canoniza a string; a regra de escopo vive no UseCase.
 */
final class NormalizadorNome
{
    /** Nome canônico (upper + espaços colapsados); null se ausente/vazio. */
    public static function normalizar(?string $nome): ?string
    {
        if ($nome === null) {
            return null;
        }

        $limpo = preg_replace('/\s+/u', ' ', trim($nome));
        if ($limpo === null || $limpo === '') {
            return null;
        }

        return mb_strtoupper($limpo, 'UTF-8');
    }
}
