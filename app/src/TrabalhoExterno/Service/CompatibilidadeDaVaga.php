<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Entity\Vaga;

/**
 * Quanto a vaga combina com o currículo de quem lê (`compat`, desenho 06 l.1011), de 0 a 100: áreas
 * em comum valem 60 (vaga sem área conta metade), a mesma UF (ou vaga sem UF, ou remota) 25 e o
 * mesmo cargo 15. Sem currículo, não há número. O selo "{N}% compatível" usa as cores de
 * {@see cor()}; a importância da vaga ganha +2 a partir de {@see ALTA}.
 */
final class CompatibilidadeDaVaga
{
    public const ALTA = 70;

    public function calcular(Vaga $v, ?Curriculo $cv): ?int
    {
        if ($cv === null) {
            return null;
        }
        $areas = $v->getAreas();
        $sobreposicao = $areas === [] ? 0.5 : count(array_intersect($areas, $cv->getAreas())) / count($areas);
        $lugar = $v->getUf() === '' || $v->getUf() === $cv->texto('uf') || $v->getModalidade() === 'Remoto';

        return (int) round($sobreposicao * 60 + ($lugar ? 25 : 0) + ($v->getCargo() === $cv->texto('cargo') ? 15 : 0));
    }

    /** A cor do selo (`cpCor`, l.1117). */
    public static function cor(int $pct): string
    {
        return match (true) {
            $pct >= self::ALTA => '#1e7b45',
            $pct >= 40 => '#0a6fa0',
            default => '#6b8494',
        };
    }
}
