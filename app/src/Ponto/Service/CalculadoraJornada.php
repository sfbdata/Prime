<?php

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\Feriado;
use App\Ponto\Entity\JornadaTenant;
use App\Ponto\Entity\RegistroPonto;

class CalculadoraJornada
{
    // Tolerância negativa: atrasos menores que este valor são ignorados (não descontam)
    private const TOLERANCIA_ATRASO_MINUTOS = 5;

    public function __construct(
        private readonly JornadaResolver $jornadaResolver,
    ) {}

    /**
     * Calcula o saldo do dia em minutos para um usuário.
     * Retorna positivo (hora extra), negativo (falta) ou 0 (dentro da tolerância).
     *
     * Regras de tolerância assimétrica:
     * - Horas extras: qualquer minuto a mais (>= 1 min) conta positivamente
     * - Atrasos: só desconta se a falta for >= TOLERANCIA_ATRASO_MINUTOS (5 min)
     *
     * @param RegistroPonto[] $batidas
     * @param Feriado[] $feriados
     */
    public function calcularSaldoDia(User $user, \DateTimeInterface $data, array $batidas, ?JornadaColaborador $jornada, array $feriados, ?JornadaTenant $jornadaTenant = null): int
    {
        // Registro incompleto não credita nem debita. Antes disto, um dia em que a pessoa bateu a
        // entrada e esqueceu o resto valia `0 - carga` = a jornada inteira negativa: em 07/2026 quatro
        // dias assim custaram −35:12 a uma colaboradora que estava no escritório. Fica antes da
        // tolerância de 5 min porque não há déficit a tolerar — não há medição.
        if ($this->registroIncompleto($user, $data, $batidas, $jornadaTenant)) {
            return 0;
        }

        $indiceDia = (int) $data->format('N');
        $isFeriado = $this->isFeriado($data, $feriados);

        // Se o colaborador tem blocos, JornadaResolver decide; 0 significa fora da jornada
        $jornadaComBlocos = $jornada !== null && !$jornada->getBlocos()->isEmpty();
        $tenantComBlocos  = $jornadaTenant !== null && !$jornadaTenant->getBlocos()->isEmpty();

        if ($jornadaComBlocos || $tenantComBlocos) {
            $cargaEsperada = $isFeriado ? 0 : $this->jornadaResolver->resolverMetaDia($user, $data, $jornadaTenant);
        } else {
            // Lógica legada: usa campos planos de JornadaColaborador
            $isDiaTrabalho = $jornada ? in_array($indiceDia, $jornada->getDiasSemana(), true) : false;
            $ehSabado = $indiceDia === 6;

            if ($ehSabado && $jornada && in_array(6, $jornada->getDiasSemana(), true) && $jornada->getCargaHorariaSabado() !== null) {
                $cargaEsperada = $isFeriado ? 0 : $jornada->getCargaHorariaSabado();
            } else {
                $cargaEsperada = ($isDiaTrabalho && !$isFeriado) ? ($jornada?->getCargaHorariaDiaria() ?? 0) : 0;
            }
        }

        $minutosTrabalhados = $this->calcularMinutosTrabalhados($batidas);

        // Dias fora da escala (domingo, feriado, sábado não escalado, etc.):
        // sem meta, apenas crédito positivo — nunca gera saldo negativo
        if ($cargaEsperada === 0) {
            return $minutosTrabalhados;
        }

        $saldo = $minutosTrabalhados - $cargaEsperada;

        if ($saldo > 0) {
            return $saldo;
        }

        if (abs($saldo) < self::TOLERANCIA_ATRASO_MINUTOS) {
            return 0;
        }

        return $saldo;
    }

    /** Retorna o Feriado correspondente à data, ou null se não for feriado. */
    public function getFeriadoDoDia(\DateTimeInterface $data, array $feriados): ?Feriado
    {
        foreach ($feriados as $feriado) {
            if ($feriado->isRecorrente()) {
                if ($feriado->getData()->format('m-d') === $data->format('m-d')) {
                    return $feriado;
                }
            } else {
                if ($feriado->getData()->format('Y-m-d') === $data->format('Y-m-d')) {
                    return $feriado;
                }
            }
        }
        return null;
    }

    private function isFeriado(\DateTimeInterface $data, array $feriados): bool
    {
        return $this->getFeriadoDoDia($data, $feriados) !== null;
    }

    /**
     * O dia tem batida, mas não as que a escala pede — logo não dá para apurar a jornada dele.
     *
     * **Dia sem batida nenhuma não é incompleto, é ausência**, e continua gerando débito. Sem essa
     * distinção a regra apagaria toda falta do sistema, que é o oposto do que ela existe para fazer.
     *
     * A forma esperada vem de `JornadaResolver::tiposEsperadosNoDia()`: dia útil cuja escala prevê
     * intervalo exige os quatro registros (é o que impede creditar o almoço de quem só bateu entrada
     * e saída); dia fora da escala exige entrada e saída.
     *
     * @param RegistroPonto[] $batidas
     */
    public function registroIncompleto(User $user, \DateTimeInterface $data, array $batidas, ?JornadaTenant $jornadaTenant = null): bool
    {
        if ($batidas === []) {
            return false;
        }

        $presentes = [];
        foreach ($batidas as $batida) {
            $presentes[$batida->getTipo()] = true;
        }

        foreach ($this->jornadaResolver->tiposEsperadosNoDia($user, $data, $jornadaTenant) as $tipo) {
            if (!isset($presentes[$tipo])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param RegistroPonto[] $batidas
     */
    public function calcularMinutosTrabalhados(array $batidas): int
    {
        $mapa = [];
        foreach ($batidas as $batida) {
            $mapa[$batida->getTipo()] = $batida->getDataHora();
        }

        $entrada = $mapa[RegistroPonto::TIPO_ENTRADA] ?? null;
        $repouso = $mapa[RegistroPonto::TIPO_REPOUSO] ?? null;
        $retorno = $mapa[RegistroPonto::TIPO_RETORNO] ?? null;
        $saida   = $mapa[RegistroPonto::TIPO_SAIDA]   ?? null;

        if (!$entrada) {
            return 0;
        }

        if ($repouso && $retorno && $saida) {
            return $this->diffMinutos($entrada, $repouso)
                 + $this->diffMinutos($retorno, $saida);
        }

        if ($saida) {
            return $this->diffMinutos($entrada, $saida);
        }

        return 0;
    }

    public function diffMinutos(\DateTimeInterface $inicio, \DateTimeInterface $fim): int
    {
        $diff = $inicio->diff($fim);
        return max(0, ($diff->days * 1440) + ($diff->h * 60) + $diff->i);
    }
}
