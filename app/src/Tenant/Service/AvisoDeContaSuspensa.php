<?php

declare(strict_types=1);

namespace App\Tenant\Service;

/**
 * Mensagem para quem tenta entrar num escritório cuja conta está suspensa (spec perfis-1.2-contas C9):
 * a mesma no aviso do TenantContextValidatorListener e na tela de seleção de escritório.
 */
final class AvisoDeContaSuspensa
{
    public static function para(string $nomeDoEscritorio): string
    {
        return sprintf('A conta do escritório %s está suspensa. Fale com o responsável pela conta.', $nomeDoEscritorio);
    }
}
