<?php

namespace App\Service\Ponto;

use App\Entity\Ponto\EscalaTrabalho;

class VerificadorAlertaPonto
{
    private const TOLERANCIA_MINUTOS = 5;

    /**
     * @return array{alertar: bool, mensagem: string, tipo: string, horario: string}
     */
    public function verificar(EscalaTrabalho $escala, \DateTimeImmutable $agora): array
    {
        if (!$escala->isAlertaHabilitado()) {
            return ['alertar' => false, 'mensagem' => '', 'tipo' => '', 'horario' => ''];
        }

        $diaSemana = (int) $agora->format('N'); // 1=Seg, ..., 7=Dom
        if (!in_array($diaSemana, $escala->getDiasSemana(), true)) {
            return ['alertar' => false, 'mensagem' => '', 'tipo' => '', 'horario' => ''];
        }

        $batidas  = $diaSemana === 6
            ? $this->batidasSabado($escala)
            : $this->batidasSemanais($escala);

        $agoraMin = (int) $agora->format('H') * 60 + (int) $agora->format('i');

        foreach ($batidas as $batida) {
            if ($batida['horario'] === null) {
                continue;
            }
            [$h, $m] = explode(':', $batida['horario']);
            if (abs($agoraMin - ((int) $h * 60 + (int) $m)) <= self::TOLERANCIA_MINUTOS) {
                return ['alertar' => true, ...$batida];
            }
        }

        return ['alertar' => false, 'mensagem' => '', 'tipo' => '', 'horario' => ''];
    }

    /**
     * @return array<array{horario: string|null, tipo: string, mensagem: string}>
     */
    private function batidasSemanais(EscalaTrabalho $escala): array
    {
        return [
            ['horario' => $escala->getEntrada1(), 'tipo' => 'entrada',  'mensagem' => 'Hora de registrar sua entrada!'],
            ['horario' => $escala->getSaida1(),   'tipo' => 'repouso',  'mensagem' => 'Hora de registrar sua saída para o intervalo!'],
            ['horario' => $escala->getEntrada2(), 'tipo' => 'retorno',  'mensagem' => 'Hora de registrar seu retorno do intervalo!'],
            ['horario' => $escala->getSaida2(),   'tipo' => 'saida',    'mensagem' => 'Hora de registrar sua saída!'],
        ];
    }

    /**
     * @return array<array{horario: string|null, tipo: string, mensagem: string}>
     */
    private function batidasSabado(EscalaTrabalho $escala): array
    {
        return [
            ['horario' => $escala->getEntradaSabado(), 'tipo' => 'entrada', 'mensagem' => 'Hora de registrar sua entrada!'],
            ['horario' => $escala->getSaidaSabado(),   'tipo' => 'saida',   'mensagem' => 'Hora de registrar sua saída!'],
        ];
    }
}
