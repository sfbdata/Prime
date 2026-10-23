<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Tenant\Tenant;

/**
 * Fuso de referência do escritório para o ponto: o da primeira sede que tem fuso, ou o de São Paulo.
 *
 * Saiu do `HomeOfficeResolver` para que a recusa de batida em horário futuro use a mesma regra.
 */
final class FusoDoEscritorio
{
    private const PADRAO = 'America/Sao_Paulo';

    public static function de(Tenant $tenant): \DateTimeZone
    {
        foreach ($tenant->getSedes() as $sede) {
            $fuso = $sede->getTimezone();
            if ($fuso !== null && $fuso !== '') {
                return new \DateTimeZone($fuso);
            }
        }

        return new \DateTimeZone(self::PADRAO);
    }
}
