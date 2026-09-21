<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Ponto\Entity\RegistroPonto;

/**
 * Decide quais batidas de um dia valem. É a decisão ÚNICA da folha: não existe outra escolha de
 * batida no cálculo, na tela ou no quadro de hoje (`docs/specs/ponto-folha-uma-batida-por-tipo.md`
 * §9.3, §9.9 e §10).
 *
 * **A regra.** Batidas do mesmo tipo, consecutivas e a até 5 min uma da outra, são o mesmo registro
 * repetido: vale a primeira, e a última quando o tipo é saída (é a mesma batida que a folha já usava,
 * e a que a limpeza de duplicatas manda preservar). Entre registros distintos, vale o primeiro de
 * cada tipo e o último de saída. Mais de um registro distinto do mesmo tipo, ou retorno antes do
 * repouso, torna o dia AMBÍGUO: ele é marcado e mantém essa mesma escolha. A regra nunca escolhe
 * sozinha um almoço menor (decisão do dono de 21/09/2026).
 *
 * **A vigência.** Dias anteriores a {@see self::VIGENCIA} usam a escolha legada, transcrita do
 * `FolhaPontoBuilder` de antes desta mudança e congelada: não recalcular o passado foi decisão do dono.
 * Com a lista do repositório, ordenada por horário, as duas escolhem as mesmas batidas (provado nos 64
 * padrões reais de `FolhaPontoPadroesHistoricosTest`). A legada só difere por preservar a ordem em que
 * as batidas chegam. 🔑 Mudar a regra no futuro exige uma vigência NOVA, nunca editar esta.
 *
 * As marcas saem do mesmo cálculo nos dois lados da vigência: não mudam número, só avisam.
 */
final class EscolhaDasBatidasDoDia
{
    /**
     * 1º dia da competência seguinte ao deploy. ⚠️ Supõe deploy em setembro/2026: se ele sair em
     * outubro ou depois, esta data tem de avançar ANTES do deploy (portão do §10.1 da spec).
     */
    public const VIGENCIA = '2026-10-01';

    /** A janela da D-1 da frente de duplicatas: repetição a até 5 min é o mesmo registro. */
    public const JANELA_REPETICAO_SEGUNDOS = 300;

    private readonly \DateTimeImmutable $inicioDaVigencia;

    public function __construct(string $vigencia = self::VIGENCIA)
    {
        $this->inicioDaVigencia = new \DateTimeImmutable($vigencia . ' 00:00:00');
    }

    public function vigencia(): \DateTimeImmutable
    {
        return $this->inicioDaVigencia;
    }

    /**
     * @param RegistroPonto[] $batidasDoDia todas as batidas de UM dia
     * @param ?\DateTimeInterface $dia o dia apurado; sem ele, vale o da primeira batida
     */
    public function escolher(array $batidasDoDia, ?\DateTimeInterface $dia = null): BatidasEscolhidas
    {
        $batidasDoDia = array_values($batidasDoDia);
        $dia ??= ($batidasDoDia[0] ?? null)?->getDataHora();
        $regra = $dia !== null && $this->antesDaVigencia($dia)
            ? BatidasEscolhidas::REGRA_LEGADA
            : BatidasEscolhidas::REGRA_UNICA;

        $ordenadas = $this->ordenar($batidasDoDia);
        $registros = $this->registros($ordenadas);

        $escolhidas = $regra === BatidasEscolhidas::REGRA_LEGADA
            ? $this->escolhaLegada($batidasDoDia)
            : $this->escolhaUnica($registros);

        $desconsideradas = array_values(array_filter(
            $ordenadas,
            static fn (RegistroPonto $batida): bool => !in_array($batida, $escolhidas, true),
        ));

        return new BatidasEscolhidas(
            $regra,
            $escolhidas[RegistroPonto::TIPO_ENTRADA] ?? null,
            $escolhidas[RegistroPonto::TIPO_REPOUSO] ?? null,
            $escolhidas[RegistroPonto::TIPO_RETORNO] ?? null,
            $escolhidas[RegistroPonto::TIPO_SAIDA] ?? null,
            $desconsideradas,
            $this->marcas($registros),
        );
    }

    private function antesDaVigencia(\DateTimeInterface $dia): bool
    {
        return \DateTimeImmutable::createFromInterface($dia)->setTime(0, 0, 0) < $this->inicioDaVigencia;
    }

    /**
     * Horário, e no mesmo segundo o id (o registro gravado primeiro). `usort` é estável, então
     * batidas sem id (ainda não gravadas) mantêm a ordem em que chegaram.
     *
     * @param list<RegistroPonto> $batidas
     * @return list<RegistroPonto>
     */
    private function ordenar(array $batidas): array
    {
        usort($batidas, static function (RegistroPonto $a, RegistroPonto $b): int {
            $porHorario = $a->getDataHora()->getTimestamp() <=> $b->getDataHora()->getTimestamp();
            if ($porHorario !== 0) {
                return $porHorario;
            }

            return ($a->getId() ?? PHP_INT_MAX) <=> ($b->getId() ?? PHP_INT_MAX);
        });

        return $batidas;
    }

    /**
     * Agrupa as repetições: mesmo tipo, consecutivas, cada uma a até 5 min da anterior do grupo.
     *
     * @param list<RegistroPonto> $ordenadas
     * @return list<list<RegistroPonto>> um grupo por registro distinto, em ordem de horário
     */
    private function registros(array $ordenadas): array
    {
        $registros = [];
        foreach ($ordenadas as $batida) {
            $ultimo = array_key_last($registros);
            if ($ultimo !== null) {
                $anterior = $registros[$ultimo][array_key_last($registros[$ultimo])];
                $segundos = $batida->getDataHora()->getTimestamp() - $anterior->getDataHora()->getTimestamp();
                if ($anterior->getTipo() === $batida->getTipo() && $segundos <= self::JANELA_REPETICAO_SEGUNDOS) {
                    $registros[$ultimo][] = $batida;
                    continue;
                }
            }
            $registros[] = [$batida];
        }

        return $registros;
    }

    /**
     * O primeiro registro de cada tipo e o último de saída; de um registro repetido, a primeira batida
     * (a última na saída).
     *
     * @param list<list<RegistroPonto>> $registros
     * @return array<string, RegistroPonto>
     */
    private function escolhaUnica(array $registros): array
    {
        $escolhidas = [];
        foreach ($registros as $grupo) {
            $tipo = $grupo[0]->getTipo();
            if ($tipo === RegistroPonto::TIPO_SAIDA) {
                $escolhidas[$tipo] = $grupo[array_key_last($grupo)];
                continue;
            }
            $escolhidas[$tipo] ??= $grupo[0];
        }

        return $escolhidas;
    }

    /**
     * A escolha de antes da vigência, transcrita de `FolhaPontoBuilder::buildRows` (`:49-70` até
     * 21/09/2026): a primeira batida DA LISTA de cada tipo e, na saída, a de horário mais tarde.
     * Congelada: não "melhorar".
     *
     * @param list<RegistroPonto> $batidas na ordem em que chegaram
     * @return array<string, RegistroPonto>
     */
    private function escolhaLegada(array $batidas): array
    {
        $escolhidas = [];
        foreach ($batidas as $batida) {
            $tipo = $batida->getTipo();
            if (!isset($escolhidas[$tipo])) {
                $escolhidas[$tipo] = $batida;
                continue;
            }
            if ($tipo === RegistroPonto::TIPO_SAIDA && $batida->getDataHora() > $escolhidas[$tipo]->getDataHora()) {
                $escolhidas[$tipo] = $batida;
            }
        }

        return $escolhidas;
    }

    /**
     * @param list<list<RegistroPonto>> $registros
     * @return list<string>
     */
    private function marcas(array $registros): array
    {
        $porTipo = [];
        foreach ($registros as $grupo) {
            $porTipo[$grupo[0]->getTipo()][] = $grupo[0]->getDataHora();
        }

        $marcas = [];
        foreach ([
            RegistroPonto::TIPO_ENTRADA => BatidasEscolhidas::MARCA_ENTRADAS_DISTINTAS,
            RegistroPonto::TIPO_REPOUSO => BatidasEscolhidas::MARCA_REPOUSOS_DISTINTOS,
            RegistroPonto::TIPO_RETORNO => BatidasEscolhidas::MARCA_RETORNOS_DISTINTOS,
            RegistroPonto::TIPO_SAIDA   => BatidasEscolhidas::MARCA_SAIDAS_DISTINTAS,
        ] as $tipo => $marca) {
            if (count($porTipo[$tipo] ?? []) > 1) {
                $marcas[] = $marca;
            }
        }

        $primeiroRetorno = $porTipo[RegistroPonto::TIPO_RETORNO][0] ?? null;
        $primeiroRepouso = $porTipo[RegistroPonto::TIPO_REPOUSO][0] ?? null;
        if ($primeiroRetorno !== null && $primeiroRepouso !== null && $primeiroRetorno <= $primeiroRepouso) {
            $marcas[] = BatidasEscolhidas::MARCA_RETORNO_ANTES_DO_REPOUSO;
        }

        return $marcas;
    }
}
