<?php

declare(strict_types=1);

namespace App\ServiceDesk\Repository;

use Doctrine\DBAL\Types\Types;

/**
 * Consulta que atravessa os escritórios de propósito (webhook do gateway sem tenant; Master com outro
 * escritório em contexto). Desliga o TenantFilter só durante a consulta e o religa com o mesmo tenant.
 */
trait SemFiltroDeTenant
{
    /** @template T @param \Closure():T $consulta @return T */
    private function semFiltroDeTenant(\Closure $consulta): mixed
    {
        $filtros = $this->getEntityManager()->getFilters();
        $estavaLigado = $filtros->isEnabled('tenant');
        $parametro = $estavaLigado ? $filtros->getFilter('tenant')->getParameter('tenant') : null;
        if ($estavaLigado) {
            $filtros->disable('tenant');
        }
        try {
            return $consulta();
        } finally {
            if ($estavaLigado) {
                $filtros->enable('tenant')->setParameter('tenant', (int) trim((string) $parametro, "'"), Types::INTEGER);
            }
        }
    }
}
