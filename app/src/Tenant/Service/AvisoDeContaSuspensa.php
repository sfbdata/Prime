<?php

declare(strict_types=1);

namespace App\Tenant\Service;

/**
 * Mensagens para quem tenta entrar num escritório cuja conta está fora do ar: a mesma no aviso do
 * TenantContextValidatorListener e na tela de seleção de escritório, sem duplicar.
 *  - conta suspensa pelo Master: spec perfis-1.2-contas C9;
 *  - conta excluída, em quarentena até a purga: C9b.
 */
final class AvisoDeContaSuspensa
{
    public static function para(string $nomeDoEscritorio): string
    {
        return sprintf('A conta do escritório %s está suspensa. Fale com o responsável pela conta.', $nomeDoEscritorio);
    }

    public static function paraExcluida(string $nomeDoEscritorio): string
    {
        return sprintf('A conta do escritório %s foi excluída. Fale com o responsável pela conta.', $nomeDoEscritorio);
    }
}
