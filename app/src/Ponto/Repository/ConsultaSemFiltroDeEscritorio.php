<?php

declare(strict_types=1);

namespace App\Ponto\Repository;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Roda uma consulta com o filtro de escritório suspenso. Só para consulta que já leva o escritório escrito à mão
 * (`x.tenant = :tenant`): a jornada é lida do escritório da URL, da folha ou da batida, e o choque lê a da mesma pessoa
 * em outro escritório (spec seguranca-b-jornada §3.2 e §3.3).
 */
trait ConsultaSemFiltroDeEscritorio
{
    abstract protected function getEntityManager(): EntityManagerInterface;

    /**
     * @template T
     *
     * @param callable(): T $consulta
     *
     * @return T
     */
    private function semFiltroDeEscritorio(callable $consulta): mixed
    {
        $filtros = $this->getEntityManager()->getFilters();
        $estavaLigado = $filtros->isEnabled('tenant');
        if ($estavaLigado) {
            $filtros->suspend('tenant');
        }
        try {
            return $consulta();
        } finally {
            if ($estavaLigado) {
                $filtros->restore('tenant');
            }
        }
    }
}
