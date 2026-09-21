<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Ponto\Entity\RegistroPonto;

/**
 * Decide quais batidas de um dia valem. É a decisão ÚNICA: a folha (células, links, intervalo, minutos
 * e saldo), o quadro "suas batidas de hoje" e o aviso de jornada leem esta escolha
 * (`docs/specs/ponto-folha-uma-batida-por-tipo.md` §9.3, §9.9 e §10). Fora dela ficam só as validações
 * da batida nova no servidor, que consultam o repositório: `findRepousoDoDia` (o primeiro repouso, o
 * mesmo daqui) e `findUltimaSaida` (a interjornada, entre dias).
 *
 * **A regra.** Batidas do mesmo tipo, consecutivas, a até 5 min uma da outra e as duas feitas pelo
 * próprio colaborador são o mesmo registro repetido — é a D1 da frente de duplicatas. Aprovação de
 * esquecimento e lançamento manual nunca se juntam: sobreposição deles é para o admin conferir. Do
 * registro repetido vale a primeira batida, e na saída a de horário mais tarde (no empate de segundo, a
 * gravada primeiro): é a mesma batida que a folha já usava, e a que a limpeza de duplicatas preserva.
 * Entre registros distintos, vale o primeiro de cada tipo e o último de saída. Mais de um registro distinto do mesmo tipo, ou retorno antes do
 * repouso, torna o dia AMBÍGUO: ele é marcado e mantém essa mesma escolha. A regra nunca escolhe
 * sozinha um almoço menor (decisão do dono de 21/09/2026).
 *
 * **A vigência.** Dias anteriores a {@see self::VIGENCIA} usam a escolha legada, transcrita do
 * `FolhaPontoBuilder` de antes desta mudança e congelada: não recalcular o passado foi decisão do dono.
 * Com a lista em ordem de horário e, no empate, de id, as duas escolhem os mesmos registros (os
 * números estão provados nos 64 padrões reais de `FolhaPontoPadroesHistoricosTest`). A legada só difere
 * por preservar a ordem em que as batidas chegam, e o repositório ordena só por horário. 🔑 Mudar a
 * regra no futuro exige uma vigência NOVA, nunca editar esta — e o mesmo vale para a conta de minutos
 * (`CalculadoraJornada::calcularMinutosDaEscolha`), que é uma só para as duas regras.
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

        // Só os quatro tipos: batida de tipo fora deles (dado gravado por fora do `setTipo`) fica entre
        // as desconsideradas, e o dia continua "com batida" para o registro incompleto, como antes.
        $escolhidas = array_intersect_key(
            $regra === BatidasEscolhidas::REGRA_LEGADA
                ? $this->escolhaLegada($batidasDoDia)
                : $this->escolhaUnica($registros),
            array_flip(RegistroPonto::TIPOS_VALIDOS),
        );

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
     * Público para quem recebe uma lista solta (os métodos de lista da `CalculadoraJornada`) poder
     * entregá-la na ordem do repositório; a regra legada preserva a ordem em que as batidas chegam.
     *
     * @param RegistroPonto[] $batidas
     * @return list<RegistroPonto>
     */
    public function ordenar(array $batidas): array
    {
        $batidas = array_values($batidas);

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
                if ($anterior->getTipo() === $batida->getTipo()
                    && $segundos <= self::JANELA_REPETICAO_SEGUNDOS
                    && $this->ehDoColaborador($anterior)
                    && $this->ehDoColaborador($batida)) {
                    $registros[$ultimo][] = $batida;
                    continue;
                }
            }
            $registros[] = [$batida];
        }

        return $registros;
    }

    /**
     * Batida feita pelo próprio colaborador: sem observação e sem a marca de lançamento manual. A
     * aprovação de esquecimento grava observação fixa; o lançamento e a edição do admin gravam o
     * snapshot `Lançamento manual`.
     */
    private function ehDoColaborador(RegistroPonto $batida): bool
    {
        return $batida->getObservacao() === null
            && $batida->getSedeNomeSnapshot() !== RegistroPonto::SNAPSHOT_LANCAMENTO_MANUAL;
    }

    /**
     * O primeiro registro de cada tipo e, na saída, o de horário mais tarde. Dentro de um registro
     * repetido vale a primeira batida e, na saída, a mais tarde. No empate de segundo, a que veio antes
     * na ordem (horário e id) — o mesmo que a regra legada escolhe quando a lista chega nessa ordem. O
     * repositório ordena só por horário: num empate de segundo, a legada segue a ordem que o banco
     * devolver, e os minutos são os mesmos (a coluna não guarda fração de segundo).
     *
     * @param list<list<RegistroPonto>> $registros
     * @return array<string, RegistroPonto>
     */
    private function escolhaUnica(array $registros): array
    {
        $escolhidas = [];
        foreach ($registros as $grupo) {
            $tipo = $grupo[0]->getTipo();
            if ($tipo !== RegistroPonto::TIPO_SAIDA) {
                $escolhidas[$tipo] ??= $grupo[0];
                continue;
            }
            foreach ($grupo as $saida) {
                if (!isset($escolhidas[$tipo]) || $saida->getDataHora() > $escolhidas[$tipo]->getDataHora()) {
                    $escolhidas[$tipo] = $saida;
                }
            }
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
