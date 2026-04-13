<?php

namespace App\Service\Ponto;

use App\Entity\Ponto\RegistroPonto;
use App\Entity\Ponto\EscalaTrabalho;
use App\Entity\Ponto\Feriado;
use App\Entity\Auth\User;

class CalculadoraJornada
{
    // Tolerância negativa: atrasos menores que este valor são ignorados (não descontam)
    private const TOLERANCIA_ATRASO_MINUTOS = 5;

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
    public function calcularSaldoDia(User $user, \DateTimeInterface $data, array $batidas, ?EscalaTrabalho $escala, array $feriados): int
    {
        if ((int) $data->format('N') === 7) {
            return 0;
        }

        $isFeriado = $this->isFeriado($data, $feriados);
        $indiceDia = (int) $data->format('N');
        $isDiaTrabalho = $escala ? in_array($indiceDia, $escala->getDiasSemana(), true) : false;
        $ehSabado = $indiceDia === 6;

        // Dias fora da escala (ex: escala flexível sem determinado dia) não geram saldo
        if ($escala !== null && !$isDiaTrabalho) {
            return 0;
        }

        if ($ehSabado && $escala && in_array(6, $escala->getDiasSemana(), true) && $escala->getCargaHorariaSabado() !== null) {
            $cargaEsperada = $isFeriado ? 0 : $escala->getCargaHorariaSabado();
        } else {
            $cargaEsperada = ($isDiaTrabalho && !$isFeriado) ? ($escala?->getCargaHorariaDiaria() ?? 0) : 0;
        }

        $minutosTrabalhados = $this->calcularMinutosTrabalhados($batidas);
        $saldo = $minutosTrabalhados - $cargaEsperada;

        // Horas extras: conta a partir de 1 minuto trabalhado a mais
        if ($saldo > 0) {
            return $saldo;
        }

        // Atrasos: ignora se a falta for menor que a tolerância
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

        // Jornada completa com intervalo de almoço: (repouso - entrada) + (saida - retorno)
        if ($repouso && $retorno && $saida) {
            return $this->diffMinutos($entrada, $repouso)
                 + $this->diffMinutos($retorno, $saida);
        }

        // Jornada corrida (ex: sábado) ou incompleta mas com saída registrada
        if ($saida) {
            return $this->diffMinutos($entrada, $saida);
        }

        return 0;
    }

    private function diffMinutos(\DateTimeInterface $inicio, \DateTimeInterface $fim): int
    {
        $diff = $inicio->diff($fim);
        return max(0, ($diff->days * 1440) + ($diff->h * 60) + $diff->i);
    }
}
