<?php

namespace App\Service\Ponto;

use App\Entity\Auth\User;
use App\Entity\Ponto\EscalaTrabalho;
use App\Entity\Ponto\Feriado;
use App\Entity\Ponto\JornadaTenant;
use App\Entity\Ponto\RegistroPonto;

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
    public function calcularSaldoDia(User $user, \DateTimeInterface $data, array $batidas, ?EscalaTrabalho $escala, array $feriados, ?JornadaTenant $jornadaTenant = null): int
    {
        $indiceDia = (int) $data->format('N');

        if ($indiceDia === 7) {
            return 0;
        }

        $isFeriado = $this->isFeriado($data, $feriados);

        // Se o usuário tem blocos, JornadaResolver decide; 0 significa fora da escala
        $escalaComBlocos = $escala !== null && !$escala->getBlocos()->isEmpty();
        $tenantComBlocos = $jornadaTenant !== null && !$jornadaTenant->getBlocos()->isEmpty();

        if ($escalaComBlocos || $tenantComBlocos) {
            $cargaEsperada = $isFeriado ? 0 : $this->jornadaResolver->resolverMetaDia($user, $data, $jornadaTenant);
        } else {
            // Lógica legada: usa campos planos de EscalaTrabalho
            $isDiaTrabalho = $escala ? in_array($indiceDia, $escala->getDiasSemana(), true) : false;
            $ehSabado = $indiceDia === 6;

            if ($escala !== null && !$isDiaTrabalho) {
                return 0;
            }

            if ($ehSabado && $escala && in_array(6, $escala->getDiasSemana(), true) && $escala->getCargaHorariaSabado() !== null) {
                $cargaEsperada = $isFeriado ? 0 : $escala->getCargaHorariaSabado();
            } else {
                $cargaEsperada = ($isDiaTrabalho && !$isFeriado) ? ($escala?->getCargaHorariaDiaria() ?? 0) : 0;
            }
        }

        $minutosTrabalhados = $this->calcularMinutosTrabalhados($batidas);
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

    private function diffMinutos(\DateTimeInterface $inicio, \DateTimeInterface $fim): int
    {
        $diff = $inicio->diff($fim);
        return max(0, ($diff->days * 1440) + ($diff->h * 60) + $diff->i);
    }
}
