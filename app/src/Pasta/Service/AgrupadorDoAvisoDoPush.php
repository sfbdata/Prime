<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Djen\Entity\PublicacaoDjen;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaProcesso;

/**
 * Agrupa as publicações novas por pasta (spec push-avisar-quando-chegar §3, "um aviso por pasta por
 * captação"): cada publicação vai para TODA pasta viva que tem o processo dela; a pasta com dois
 * processos que receberam publicação recebe as duas num aviso só. A ordem das publicações é a de
 * entrada (a consulta já vem da mais recente para a mais antiga); a das pastas, a dos vínculos.
 */
final class AgrupadorDoAvisoDoPush
{
    /**
     * @param list<PastaProcesso>  $vinculos     pastas vivas dos processos (já filtradas pelo escritório)
     * @param list<PublicacaoDjen> $publicacoes  novas desta captação, vinculadas a processo
     *
     * @return list<array{pasta: Pasta, publicacoes: non-empty-list<PublicacaoDjen>}>
     */
    public static function agrupar(array $vinculos, array $publicacoes): array
    {
        $pastasDoProcesso = [];
        foreach ($vinculos as $vinculo) {
            $pastasDoProcesso[(int) $vinculo->getProcesso()->getId()][] = $vinculo->getPasta();
        }

        $grupos = [];
        foreach ($publicacoes as $publicacao) {
            foreach ($pastasDoProcesso[(int) $publicacao->getProcesso()?->getId()] ?? [] as $pasta) {
                $grupos[(int) $pasta->getId()] ??= ['pasta' => $pasta, 'publicacoes' => []];
                $grupos[(int) $pasta->getId()]['publicacoes'][] = $publicacao;
            }
        }

        return array_values($grupos);
    }
}
