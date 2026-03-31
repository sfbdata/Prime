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
     * Calcula o saldo do dia para um usuário.
     * @param RegistroPonto[] $batidas
     * @param Feriado[] $feriados
     */
    public function calcularSaldoDia(User $user, \DateTimeInterface $data, array $batidas, ?EscalaTrabalho $escala, array $feriados): int
    {
        // Se for feriado ou final de semana e não houver escala, carga horária é 0
        $isFeriado = $this->isFeriado($data, $feriados);
        $isDiaTrabalho = $escala ? in_array((int)$data->format('N'), $escala->getDiasSemana()) : false;

        $cargaEsperada = ($isDiaTrabalho && !$isFeriado) ? ($escala ? $escala->getCargaHorariaDiaria() : 0) : 0;

        $minutosTrabalhados = $this->calcularMinutosTrabalhados($batidas);

        // Aplicar tolerância se estiver muito próximo da carga esperada
        if (abs($minutosTrabalhados - $cargaEsperada) <= self::TOLERANCIA_MINUTOS) {
            return 0;
        }

        return $minutosTrabalhados - $cargaEsperada;
    }

    private function isFeriado(\DateTimeInterface $data, array $feriados): bool
    {
        foreach ($feriados as $feriado) {
            if ($feriado->isRecorrente()) {
                if ($feriado->getData()->format('m-d') === $data->format('m-d')) return true;
            } else {
                if ($feriado->getData()->format('Y-m-d') === $data->format('Y-m-d')) return true;
            }
        }
        return false;
    }

    /**
     * @param RegistroPonto[] $batidas
     */
    private function calcularMinutosTrabalhados(array $batidas): int
    {
        if (count($batidas) < 2) return 0;

        // Ordenar batidas por hora
        usort($batidas, fn($a, $b) => $a->getDataHora() <=> $b->getDataHora());

        $totalMinutos = 0;
        $entrada = null;

        foreach ($batidas as $batida) {
            if (str_contains($batida->getTipo(), 'entrada')) {
                $entrada = $batida->getDataHora();
            } elseif (str_contains($batida->getTipo(), 'saida') && $entrada) {
                $intervalo = $entrada->diff($batida->getDataHora());
                $totalMinutos += ($intervalo->h * 60) + $intervalo->i;
                $entrada = null;
            }
        }

        return $totalMinutos;
    }
}
