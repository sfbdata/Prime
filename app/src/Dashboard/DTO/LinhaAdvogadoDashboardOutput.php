<?php

declare(strict_types=1);

namespace App\Dashboard\DTO;

final class LinhaAdvogadoDashboardOutput
{
    public function __construct(
        public readonly int     $userId,
        public readonly string  $nomeAdvogado,
        public readonly ?string $cargoNome,
        public readonly ?string $fotoUrl,
        // Tarefa
        public readonly int    $totalMetas,
        public readonly int    $metasAtivas,
        public readonly int    $metasVencidas,
        public readonly int    $prazosProximos,
        // Pasta
        public readonly int    $totalDemandas,
        public readonly int    $demandasAtivas,
        /** Pastas abertas POR este colaborador (criadoPor), não as que ele responde. */
        public readonly int    $pastasCriadas,
        // TENDÊNCIA — mesmas três métricas no período anterior de mesma duração; null quando
        // não há período completo (sem período não há com o que comparar).
        public readonly ?int   $totalMetasAnterior = null,
        public readonly ?int   $totalDemandasAnterior = null,
        public readonly ?int   $pastasCriadasAnterior = null,
        // TENDÊNCIA DO ESTOQUE — vencidas e prazos próximos, que não se reconstroem do passado,
        // lidos da foto diária (`dashboard_foto`) do dia `data_de − 1`. Null quando não há
        // período OU quando esta pessoa não foi fotografada naquele dia: nada é inventado.
        // (Metas/Demandas ativas ficam de fora: o painel as filtra por criação no período e a
        // foto guarda o estoque inteiro — bases diferentes, comparação sem lastro.)
        public readonly ?int   $metasVencidasAnterior = null,
        public readonly ?int   $prazosProximosAnterior = null,
    ) {}
}
