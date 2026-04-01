<?php

namespace App\Service\Ponto;

use App\Entity\Ponto\RegistroPonto;

class FolhaPontoBuilder
{
    /**
     * @param RegistroPonto[] $batidas
     * @return array<int, array{diaMes: string, diaSemana: string, entrada: string, repouso: string, retorno: string, saida: string, fimSemana: bool}>
     */
    public function buildRows(
        \DateTimeImmutable $inicioMes,
        \DateTimeImmutable $fimMes,
        array $batidas,
        bool $includeEmptyDays = true,
        bool $orderDesc = false
    ): array {
        $registrosPorDia = [];
        foreach ($batidas as $batida) {
            $chaveDia = $batida->getDataHora()->format('Y-m-d');
            $tipo = $batida->getTipo();

            if (!isset($registrosPorDia[$chaveDia])) {
                $registrosPorDia[$chaveDia] = [];
            }

            if (!isset($registrosPorDia[$chaveDia][$tipo])) {
                $registrosPorDia[$chaveDia][$tipo] = $batida;
                continue;
            }

            $registroAtual = $registrosPorDia[$chaveDia][$tipo];
            if (
                $tipo === RegistroPonto::TIPO_SAIDA
                && $batida->getDataHora() > $registroAtual->getDataHora()
            ) {
                $registrosPorDia[$chaveDia][$tipo] = $batida;
            }
        }

        $diasSemana = [
            1 => 'Segunda',
            2 => 'Terça',
            3 => 'Quarta',
            4 => 'Quinta',
            5 => 'Sexta',
            6 => 'Sábado',
            7 => 'Domingo',
        ];

        $rows = [];
        for ($dia = $inicioMes; $dia <= $fimMes; $dia = $dia->modify('+1 day')) {
            $chaveDia = $dia->format('Y-m-d');
            $indiceDiaSemana = (int) $dia->format('N');

            $row = [
                'diaMes' => $dia->format('d'),
                'diaSemana' => $diasSemana[$indiceDiaSemana],
                'entrada' => isset($registrosPorDia[$chaveDia][RegistroPonto::TIPO_ENTRADA]) ? $registrosPorDia[$chaveDia][RegistroPonto::TIPO_ENTRADA]->getDataHora()->format('H:i:s') : '',
                'repouso' => isset($registrosPorDia[$chaveDia][RegistroPonto::TIPO_REPOUSO]) ? $registrosPorDia[$chaveDia][RegistroPonto::TIPO_REPOUSO]->getDataHora()->format('H:i:s') : '',
                'retorno' => isset($registrosPorDia[$chaveDia][RegistroPonto::TIPO_RETORNO]) ? $registrosPorDia[$chaveDia][RegistroPonto::TIPO_RETORNO]->getDataHora()->format('H:i:s') : '',
                'saida' => isset($registrosPorDia[$chaveDia][RegistroPonto::TIPO_SAIDA]) ? $registrosPorDia[$chaveDia][RegistroPonto::TIPO_SAIDA]->getDataHora()->format('H:i:s') : '',
                'fimSemana' => $indiceDiaSemana >= 6,
            ];

            if (
                !$includeEmptyDays
                && $row['entrada'] === ''
                && $row['repouso'] === ''
                && $row['retorno'] === ''
                && $row['saida'] === ''
            ) {
                continue;
            }

            $rows[] = $row;
        }

        if ($orderDesc) {
            $rows = array_reverse($rows);
        }

        return $rows;
    }
}
