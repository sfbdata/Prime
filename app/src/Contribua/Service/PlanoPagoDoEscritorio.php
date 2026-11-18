<?php

declare(strict_types=1);

namespace App\Contribua\Service;

use App\Contribua\Entity\Plano;
use App\Contribua\Repository\EspacoDoEscritorioRepository;

/**
 * Leitura pública, para os outros domínios, de "este escritório paga plano?" (spec `ia-liberada-por-indicacao.md`,
 * D6). É pago o escritório cujo plano no `EspacoDoEscritorio` tem código diferente do Free; sem linha de espaço é Free.
 * O limite próprio do Master (`definirLimite`, "sem limite") NÃO conta como plano pago: só o plano vigente.
 *
 * Só o Contribua consulta o espaço (`EspacoDoEscritorioSoNoContribuaArquiteturaTest`); quem precisa da resposta
 * pergunta aqui, sempre por tenantId explícito.
 */
// Não-final: permite substituição por mock nos testes de quem a consome.
class PlanoPagoDoEscritorio
{
    public function __construct(
        private readonly EspacoDoEscritorioRepository $espacos,
    ) {
    }

    public function temPlanoPago(int $tenantId): bool
    {
        $espaco = $this->espacos->doTenant($tenantId);

        return $espaco !== null && $espaco->getPlano()->getCodigo() !== Plano::CODIGO_FREE;
    }

    /**
     * Dos escritórios dados, os que NÃO têm plano pago (numa consulta só).
     *
     * @param list<int> $tenantIds
     *
     * @return list<int>
     */
    public function semPlanoPago(array $tenantIds): array
    {
        $espacos = $this->espacos->dosTenants($tenantIds);

        return array_values(array_filter(
            $tenantIds,
            static fn (int $id): bool => !isset($espacos[$id]) || $espacos[$id]->getPlano()->getCodigo() === Plano::CODIGO_FREE,
        ));
    }
}
