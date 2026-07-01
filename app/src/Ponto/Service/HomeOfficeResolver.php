<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Repository\HomeOfficeConfigRepository;

/**
 * Decide se um colaborador está liberado para bater ponto em home office (fora do raio das sedes)
 * numa determinada data. É a única fonte da verdade do bypass de geofencing — usada tanto na batida
 * quanto no frontend. Sempre consulta a config do (user, tenant) com filtro de tenant explícito.
 */
final class HomeOfficeResolver
{
    private const TIMEZONE_PADRAO = 'America/Sao_Paulo';

    public function __construct(
        private readonly HomeOfficeConfigRepository $configRepository,
    ) {
    }

    /**
     * @param \DateTimeInterface $instante Momento da batida (instante absoluto); é normalizado para o
     *                                     timezone de referência do tenant antes de extrair o dia.
     */
    public function estaLiberado(User $user, Tenant $tenant, \DateTimeInterface $instante): bool
    {
        $config = $this->configRepository->findByUserETenant($user, $tenant);
        if ($config === null || !$config->isAtivo()) {
            return false;
        }

        $local = \DateTimeImmutable::createFromInterface($instante)
            ->setTimezone(new \DateTimeZone($this->timezoneDoTenant($tenant)));
        $diaSemana = (int) $local->format('N'); // 1=Segunda ... 7=Domingo
        $diaStr = $local->format('Y-m-d');

        // Data avulsa vale por si, independentemente da vigência.
        if (in_array($diaStr, $config->getDatasAvulsas(), true)) {
            return true;
        }

        // Recorrência semanal, delimitada pela vigência (quando houver).
        if (!in_array($diaSemana, $config->getDiasSemana(), true)) {
            return false;
        }

        $inicio = $config->getVigenciaInicio();
        if ($inicio !== null && $diaStr < $inicio->format('Y-m-d')) {
            return false;
        }

        $fim = $config->getVigenciaFim();
        if ($fim !== null && $diaStr > $fim->format('Y-m-d')) {
            return false;
        }

        return true;
    }

    private function timezoneDoTenant(Tenant $tenant): string
    {
        foreach ($tenant->getSedes() as $sede) {
            $tz = $sede->getTimezone();
            if ($tz !== null && $tz !== '') {
                return $tz;
            }
        }

        return self::TIMEZONE_PADRAO;
    }
}
