<?php

declare(strict_types=1);

namespace App\Dashboard\Inteligencia;

use App\Dashboard\DTO\LinhaAdvogadoDashboardOutput;

/**
 * Plano "Aumentar o nível" (dc L1284-1325; porte de `planoCrescimento()`, bluejus-intelligence.js L158-184),
 * por regras fixas sobre dado que o sistema tem. Nada é estimado por modelo.
 *
 * BASE: a meta de pastas do mês vigente (RitmoDaMeta). O desenho parte de um "objetivo" calculado de
 * períodos anteriores, que o sistema não guarda; aqui a base é a meta que o escritório definiu. Sem meta,
 * fora do mês inteiro, com filtro de responsável/cargo ou com pasta oculta pelo cadeado, não há RitmoDaMeta
 * e o plano não existe.
 *
 * FÓRMULAS (dias úteis, como o RitmoDaMeta):
 *  - nova meta  = min(9999, ceil(meta atual x (1 + nível))), nível em 0, 10, 20 ou 35%;
 *  - faltam     = max(0, nova meta - pastas criadas no mês);
 *  - dias       = max(1, dias úteis que restam);  ritmo = faltam / dias;
 *  - teto       = max(ritmo atual x 1,25 ; 0,01), onde ritmo atual = criadas / dias úteis decorridos
 *    (o desenho usa max(melhor ritmo, atual x 1,25) e seu "melhor ritmo" é atual x 1,15: o teto é o mesmo);
 *  - viabilidade pelo esforço = ritmo / teto: até 0,8 Viável, até 1 Desafiador, até 1,25 Arriscado,
 *    acima Fora da capacidade. Sem ritmo atual (zero) e com pasta por criar: "Sem base" (não se inventa teto);
 *  - por pessoa: o que falta se divide pelas pastas que cada uma já criou no mês (capacidade real; quem
 *    não criou nenhuma fica de fora), pelo maior resto, de modo que a soma fecha em "faltam";
 *  - marcos: um por semana útil (5 dias úteis), alvo = criadas + ritmo x dias; o último é o "Fim" (= nova meta).
 */
final readonly class MontarPlanosDeCrescimento
{
    private const DIAS_POR_SEMANA = 5;
    private const MAX_MARCOS      = 6;
    private const MAX_PESSOAS     = 6;

    /**
     * @param list<LinhaAdvogadoDashboardOutput> $linhas as da tabela Desempenho (já filtradas)
     *
     * @return array<int, PlanoDeCrescimento> um plano por nível, indexado pelo `id` do nível
     */
    public function montar(RitmoDaMeta $meta, LeituraDoRitmo $ritmo, array $linhas): array
    {
        $planos = [];
        foreach (NivelDeCrescimento::cases() as $nivel) {
            $planos[$nivel->value] = $this->plano($nivel, $meta, $ritmo, $linhas);
        }

        return $planos;
    }

    /** @param list<LinhaAdvogadoDashboardOutput> $linhas */
    private function plano(NivelDeCrescimento $nivel, RitmoDaMeta $meta, LeituraDoRitmo $ritmo, array $linhas): PlanoDeCrescimento
    {
        $nova      = $nivel->novaMeta($meta->meta);
        $faltam    = max(0, $nova - $meta->realizado);
        $dias      = max(1, $meta->diasUteisTotal - $meta->diaUtil);
        $porDia    = $faltam / $dias;
        $esforco   = $porDia / max($meta->ritmoAtual * 1.25, 0.01);
        $viab      = $this->viabilidade($faltam, $meta->ritmoAtual, $esforco);
        $pessoas   = $this->pessoas($faltam, $linhas);

        return new PlanoDeCrescimento(
            $nivel,
            $meta->meta,
            $nova,
            $meta->realizado,
            $faltam,
            $dias,
            $porDia,
            $meta->ritmoAtual,
            $viab,
            $pessoas,
            $this->marcos($meta->realizado, $porDia, $dias, $nova),
            $this->alavancas($viab, $ritmo, $pessoas),
        );
    }

    private function viabilidade(int $faltam, float $ritmoAtual, float $esforco): ViabilidadeDoPlano
    {
        if ($faltam === 0) {
            return ViabilidadeDoPlano::Viavel;
        }

        return $ritmoAtual > 0 ? ViabilidadeDoPlano::de($esforco) : ViabilidadeDoPlano::SemBase;
    }

    /**
     * @param list<LinhaAdvogadoDashboardOutput> $linhas
     *
     * @return list<PessoaDoPlano>
     */
    private function pessoas(int $faltam, array $linhas): array
    {
        $pesos = [];
        $nomes = [];
        foreach ($linhas as $l) {
            if ($l->pastasCriadas > 0) {
                $pesos[$l->userId] = ($pesos[$l->userId] ?? 0) + $l->pastasCriadas;
                $nomes[$l->userId] = $l->nomeAdvogado;
            }
        }
        if ($faltam === 0 || $pesos === []) {
            return [];
        }

        // A soma vale para todas as pessoas com pasta criada; a lista mostra até 6, só quem tem cota maior que zero.
        $extras = array_filter($this->repartir($faltam, $pesos), static fn (int $e): bool => $e > 0);
        arsort($extras);
        $maior = max(1, max($extras));
        $saida = [];
        foreach (array_slice($extras, 0, self::MAX_PESSOAS, true) as $id => $extra) {
            $saida[] = new PessoaDoPlano($nomes[$id], $extra, (int) round($extra / $maior * 100));
        }

        return $saida;
    }

    /**
     * Divide `$total` na proporção dos pesos, pelo maior resto: a soma fecha em `$total`.
     *
     * @param array<int, int> $pesos
     *
     * @return array<int, int>
     */
    private function repartir(int $total, array $pesos): array
    {
        $soma    = array_sum($pesos);
        $extras  = [];
        $restos  = [];
        foreach ($pesos as $nome => $peso) {
            $extras[$nome] = intdiv($total * $peso, $soma);
            $restos[$nome] = ($total * $peso) % $soma;
        }
        arsort($restos);
        $sobra = $total - array_sum($extras);
        foreach (array_keys($restos) as $nome) {
            if ($sobra-- <= 0) {
                break;
            }
            ++$extras[$nome];
        }

        return $extras;
    }

    /** @return list<MarcoDoPlano> */
    private function marcos(int $realizado, float $porDia, int $dias, int $nova): array
    {
        $semanas = max(1, (int) ceil($dias / self::DIAS_POR_SEMANA));
        $marcos  = [];
        for ($i = 0; $i < min($semanas, self::MAX_MARCOS); ++$i) {
            $d        = min($dias, ($i + 1) * self::DIAS_POR_SEMANA);
            $fim = $i === $semanas - 1 || $d === $dias;
            $marcos[] = new MarcoDoPlano($i + 1, $fim, $fim ? $nova : (int) round($realizado + $porDia * $d));
        }

        return $marcos;
    }

    /**
     * @param list<PessoaDoPlano> $pessoas
     *
     * @return list<AlavancaDoPlano>
     */
    private function alavancas(ViabilidadeDoPlano $viab, LeituraDoRitmo $ritmo, array $pessoas): array
    {
        $alavancas = [];
        if ($ritmo->vencidas > 0) {
            $alavancas[] = new AlavancaDoPlano('vencidas', ['n' => $ritmo->vencidas]);
        }
        if ($ritmo->prazos > 0) {
            $alavancas[] = new AlavancaDoPlano('prazos', ['n' => $ritmo->prazos]);
        }
        if (count($pessoas) > 1) {
            $nomes       = array_map(static fn (PessoaDoPlano $p): string => explode(' ', trim($p->nome))[0], array_slice($pessoas, 0, 2));
            $alavancas[] = new AlavancaDoPlano('concentrar', ['nomes' => implode(' e ', $nomes)]);
        }
        if ($viab === ViabilidadeDoPlano::Arriscado || $viab === ViabilidadeDoPlano::ForaDaCapacidade) {
            $alavancas[] = new AlavancaDoPlano('nivel_abaixo');
        }

        return $alavancas;
    }
}
