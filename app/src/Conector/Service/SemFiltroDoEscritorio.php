<?php

declare(strict_types=1);

namespace App\Conector\Service;

use Doctrine\ORM\EntityManagerInterface;

/**
 * O único lugar do conector, fora do portão, que mexe no `TenantFilter`: suspende o filtro do escritório da sessão
 * durante uma consulta e o devolve como estava, mesmo se a consulta falhar. Nunca liga o filtro (quem liga é só o
 * {@see PortaoDoConector}, spec mcp-remoto-oauth 4.1; o `PortaoDoConectorArquiteturaTest` confere as duas coisas).
 *
 * Serve às telas do PR 8, que leem ou gravam fora do escritório da sessão com o escritório escrito à mão: as conexões
 * da pessoa em todos os escritórios dela, a linha de qualquer escritório para o Master e a revogação das concessões de
 * uma conexão de outro escritório (o filtro também entra no WHERE de um UPDATE em DQL). Molde:
 * {@see \App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository::findDoTenantParaOMaster}.
 */
final class SemFiltroDoEscritorio
{
    private const FILTRO = 'tenant';

    /**
     * @template T
     *
     * @param callable(): T $consulta
     *
     * @return T
     */
    public static function executar(EntityManagerInterface $em, callable $consulta): mixed
    {
        $filtros = $em->getFilters();
        $estavaLigado = $filtros->isEnabled(self::FILTRO);
        if ($estavaLigado) {
            $filtros->suspend(self::FILTRO);
        }
        try {
            return $consulta();
        } finally {
            if ($estavaLigado) {
                $filtros->restore(self::FILTRO);
            }
        }
    }
}
