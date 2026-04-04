<?php

namespace App\Service\Ponto;

use App\Entity\Ponto\RegistroPonto;
use App\Entity\Ponto\EscalaTrabalho;
use App\Entity\Ponto\Feriado;
use App\Entity\Auth\User;

class CalculadoraJornada
{
    private const TOLERANCIA_MINUTOS = 15;

    /**
     * Calcula o saldo do dia em minutos para um usuário.
     * Retorna positivo (hora extra), negativo (falta) ou 0 (dentro da tolerância).
     *
     * @param RegistroPonto[] $batidas
     * @param Feriado[] $feriados
     */
    public function calcularSaldoDia(User $user, \DateTimeInterface $data, array $batidas, ?EscalaTrabalho $escala, array $feriados): int
    {
        $isFeriado = $this->isFeriado($data, $feriados);
        $isDiaTrabalho = $escala ? in_array((int) $data->format('N'), $escala->getDiasSemana()) : false;
        $ehSabado = (int) $data->format('N') === 6;

        if ($ehSabado && $escala && in_array(6, $escala->getDiasSemana()) && $escala->getCargaHorariaSabado() !== null) {
            $cargaEsperada = $isFeriado ? 0 : $escala->getCargaHorariaSabado();
        } else {
            $cargaEsperada = ($isDiaTrabalho && !$isFeriado) ? ($escala?->getCargaHorariaDiaria() ?? 0) : 0;
        }

        $minutosTrabalhados = $this->calcularMinutosTrabalhados($batidas);

        if (abs($minutosTrabalhados - $cargaEsperada) <= self::TOLERANCIA_MINUTOS) {
            return 0;
        }

        return $minutosTrabalhados - $cargaEsperada;
    }

    private function isFeriado(\DateTimeInterface $data, array $feriados): bool
    {
        foreach ($feriados as $feriado) {
            if ($feriado->isRecorrente()) {
                if ($feriado->getData()->format('m-d') === $data->format('m-d')) {
                    return true;
                }
            } else {
                if ($feriado->getData()->format('Y-m-d') === $data->format('Y-m-d')) {
                    return true;
                }
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
