<?php

declare(strict_types=1);

namespace App\Dashboard\DTO;

/**
 * Uma linha da tabela Desempenho.
 *
 * `estoque` (PR-F): o ESTOQUE com seta pela foto diária, por coluna (metas_ativas, demandas_ativas,
 * metas_revisao, pastas_urgentes). `atual` é o estoque de HOJE sem período (o mesmo da foto, e NÃO o
 * número da célula, que o painel recorta por criação); `anterior` é o da foto de `data_de − 1`.
 * Anterior null = sem lastro (sem foto, ou foto de antes do PR-F): sem seta, nunca zero inventado.
 */
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
        // COLUNAS EXTRAS ("Adicionar coluna") — só as que o usuário ligou, na ordem da tabela:
        // chave do ColunasExtrasDoDashboard => valor. Contagem é int (zero é dado); taxa (%) e
        // tempo médio (dias) são null quando não há o que medir — a tela mostra "—".
        /** @var array<string, int|null> */
        public readonly array  $extras = [],
        // TENDÊNCIA DAS EXTRAS — o valor de cada extra de ColunasExtrasDoDashboard::TENDENCIA no período
        // anterior de mesma duração, pela MESMA fórmula. Vazio sem período completo; fora dela, sem chave.
        /** @var array<string, int|null> */
        public readonly array  $extrasAnteriores = [],
        /** @var array{atual?: array<string, int>, anterior?: array<string, int|null>} estoque com seta pela foto (ver a classe) */ public readonly array $estoque = [],
        /** @var list<string> colunas com "Zerar" ativo (DASH-014): sem link e sem seta */ public readonly array $colunasZeradas = [],
    ) {}
}
