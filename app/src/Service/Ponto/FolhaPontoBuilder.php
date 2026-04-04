<?php

namespace App\Service\Ponto;

use App\Entity\Ponto\EscalaTrabalho;
use App\Entity\Ponto\Feriado;
use App\Entity\Ponto\RegistroPonto;

class FolhaPontoBuilder
{
    public function __construct(
        private readonly CalculadoraJornada $calculadora
    ) {}

    /**
     * @param RegistroPonto[] $batidas
     * @param Feriado[] $feriados
     * @return array<int, array{diaMes: string, diaSemana: string, entrada: string, repouso: string, retorno: string, saida: string, fimSemana: bool, minutosTrabalhadosDia: int|null, saldoDia: int|null, saldoAcumulado: int|null}>
     */
    public function buildRows(
        \DateTimeImmutable $inicioMes,
        \DateTimeImmutable $fimMes,
        array $batidas,
        bool $includeEmptyDays = true,
        bool $orderDesc = false,
        ?EscalaTrabalho $escala = null,
        array $feriados = []
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
        $saldoAcumulado = 0;

        for ($dia = $inicioMes; $dia <= $fimMes; $dia = $dia->modify('+1 day')) {
            $chaveDia = $dia->format('Y-m-d');
            $indiceDiaSemana = (int) $dia->format('N');

            $row = [
                'diaMes'    => $dia->format('d'),
                'diaSemana' => $diasSemana[$indiceDiaSemana],
                'chaveDia'  => $chaveDia,
                'entrada'   => isset($registrosPorDia[$chaveDia][RegistroPonto::TIPO_ENTRADA]) ? $registrosPorDia[$chaveDia][RegistroPonto::TIPO_ENTRADA]->getDataHora()->format('H:i:s') : '',
                'repouso'   => isset($registrosPorDia[$chaveDia][RegistroPonto::TIPO_REPOUSO]) ? $registrosPorDia[$chaveDia][RegistroPonto::TIPO_REPOUSO]->getDataHora()->format('H:i:s') : '',
                'retorno'   => isset($registrosPorDia[$chaveDia][RegistroPonto::TIPO_RETORNO]) ? $registrosPorDia[$chaveDia][RegistroPonto::TIPO_RETORNO]->getDataHora()->format('H:i:s') : '',
                'saida'     => isset($registrosPorDia[$chaveDia][RegistroPonto::TIPO_SAIDA])   ? $registrosPorDia[$chaveDia][RegistroPonto::TIPO_SAIDA]->getDataHora()->format('H:i:s')   : '',
                'entradaId' => isset($registrosPorDia[$chaveDia][RegistroPonto::TIPO_ENTRADA]) ? $registrosPorDia[$chaveDia][RegistroPonto::TIPO_ENTRADA]->getId() : null,
                'repousoId' => isset($registrosPorDia[$chaveDia][RegistroPonto::TIPO_REPOUSO]) ? $registrosPorDia[$chaveDia][RegistroPonto::TIPO_REPOUSO]->getId() : null,
                'retornoId' => isset($registrosPorDia[$chaveDia][RegistroPonto::TIPO_RETORNO]) ? $registrosPorDia[$chaveDia][RegistroPonto::TIPO_RETORNO]->getId() : null,
                'saidaId'   => isset($registrosPorDia[$chaveDia][RegistroPonto::TIPO_SAIDA])   ? $registrosPorDia[$chaveDia][RegistroPonto::TIPO_SAIDA]->getId()   : null,
                'fimSemana' => $indiceDiaSemana >= 6,
                'minutosTrabalhadosDia' => null,
                'saldoDia'       => null,
                'saldoAcumulado' => null,
            ];

            if ($escala !== null) {
                $batidasDoDia = isset($registrosPorDia[$chaveDia])
                    ? array_values($registrosPorDia[$chaveDia])
                    : [];

                $minutos = $this->calculadora->calcularMinutosTrabalhados($batidasDoDia);
                $saldoDia = $this->calculadora->calcularSaldoDia($escala->getUser(), $dia, $batidasDoDia, $escala, $feriados);
                $saldoAcumulado += $saldoDia;

                $row['minutosTrabalhadosDia'] = $minutos;
                $row['saldoDia']              = $saldoDia;
                $row['saldoAcumulado']        = $saldoAcumulado;
            }

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
