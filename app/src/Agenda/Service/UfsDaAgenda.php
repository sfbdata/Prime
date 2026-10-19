<?php

declare(strict_types=1);

namespace App\Agenda\Service;

/**
 * Estados cujos feriados a Agenda mostra: o da lotação do usuário (o nome da lotação termina com a
 * UF, ex. "Maceió/AL") e o do escritório (Tenant::estado). Só entram UFs com feriado na tabela.
 */
final class UfsDaAgenda
{
    /** @return list<string> */
    public function resolver(?string $nomeDaLotacao, ?string $estadoDoEscritorio): array
    {
        $ufs = [];
        foreach ([$nomeDaLotacao, $estadoDoEscritorio] as $texto) {
            $uf = $this->ufNoFinal((string) $texto);
            if (null !== $uf && isset(CalendarioDoSistema::ESTADUAIS[$uf]) && !in_array($uf, $ufs, true)) {
                $ufs[] = $uf;
            }
        }

        return $ufs;
    }

    private function ufNoFinal(string $texto): ?string
    {
        if (1 !== preg_match('/(?:^|[\/\-\s])([A-Za-z]{2})\s*$/', trim($texto), $m)) {
            return null;
        }

        return strtoupper($m[1]);
    }
}
