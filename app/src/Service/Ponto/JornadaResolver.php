<?php

namespace App\Service\Ponto;

use App\Entity\Auth\User;
use App\Entity\Ponto\JornadaTenant;

class JornadaResolver
{
    public function resolverMetaDia(User $user, \DateTimeInterface $data, ?JornadaTenant $jornadaTenant): int
    {
        $indiceDia = (int) $data->format('N');
        $escala = $user->getEscalaTrabalho();

        // Blocos do usuário têm prioridade
        if ($escala !== null && !$escala->getBlocos()->isEmpty()) {
            foreach ($escala->getBlocos() as $bloco) {
                if (in_array($indiceDia, $bloco->getDiasSemana(), true)) {
                    return $bloco->getMinutosBloco();
                }
            }
            // Usuário tem blocos mas nenhum cobre este dia
            return 0;
        }

        // Fallback para blocos do tenant
        if ($jornadaTenant !== null && !$jornadaTenant->getBlocos()->isEmpty()) {
            foreach ($jornadaTenant->getBlocos() as $bloco) {
                if (in_array($indiceDia, $bloco->getDiasSemana(), true)) {
                    return $bloco->getMinutosBloco();
                }
            }
            // Tenant tem blocos mas nenhum cobre este dia
            return 0;
        }

        // Fallback legado: campos planos de EscalaTrabalho
        if ($escala !== null) {
            if ($indiceDia === 6) {
                return $escala->getCargaHorariaSabado() ?? 0;
            }
            if (in_array($indiceDia, $escala->getDiasSemana(), true)) {
                return $escala->getCargaHorariaDiaria() ?? 0;
            }
        }

        return 0;
    }

    public function resolverAlertaHabilitado(User $user, ?JornadaTenant $jornadaTenant): bool
    {
        $escala = $user->getEscalaTrabalho();

        if ($escala !== null) {
            return $escala->isAlertaHabilitado();
        }

        if ($jornadaTenant !== null) {
            return $jornadaTenant->isAlertaHabilitado();
        }

        return false;
    }

    /**
     * Retorna os horários esperados do bloco vigente para o dia, no formato:
     * [['horario' => 'HH:mm', 'tipo' => 'entrada|repouso|retorno|saida', 'mensagem' => '...']]
     *
     * @return array<array{horario: string|null, tipo: string, mensagem: string}>
     */
    public function resolverBatidasEsperadasHoje(User $user, \DateTimeInterface $data, ?JornadaTenant $jornadaTenant): array
    {
        $indiceDia = (int) $data->format('N');
        $escala = $user->getEscalaTrabalho();

        // Blocos do usuário têm prioridade
        if ($escala !== null && !$escala->getBlocos()->isEmpty()) {
            foreach ($escala->getBlocos() as $bloco) {
                if (in_array($indiceDia, $bloco->getDiasSemana(), true)) {
                    return $this->batidasDeBloco($bloco->getEntrada(), $bloco->getRepouso(), $bloco->getRetorno(), $bloco->getSaida());
                }
            }
            return [];
        }

        // Fallback para blocos do tenant
        if ($jornadaTenant !== null && !$jornadaTenant->getBlocos()->isEmpty()) {
            foreach ($jornadaTenant->getBlocos() as $bloco) {
                if (in_array($indiceDia, $bloco->getDiasSemana(), true)) {
                    return $this->batidasDeBloco($bloco->getEntrada(), $bloco->getRepouso(), $bloco->getRetorno(), $bloco->getSaida());
                }
            }
            return [];
        }

        // Fallback legado
        if ($escala !== null) {
            if ($indiceDia === 6 && in_array(6, $escala->getDiasSemana(), true)) {
                return $this->batidasDeBloco($escala->getEntradaSabado(), null, null, $escala->getSaidaSabado());
            }
            if (in_array($indiceDia, $escala->getDiasSemana(), true)) {
                return $this->batidasDeBloco($escala->getEntrada1(), $escala->getSaida1(), $escala->getEntrada2(), $escala->getSaida2());
            }
        }

        return [];
    }

    /**
     * @return array<array{horario: string|null, tipo: string, mensagem: string}>
     */
    private function batidasDeBloco(?string $entrada, ?string $repouso, ?string $retorno, ?string $saida): array
    {
        return [
            ['horario' => $entrada, 'tipo' => 'entrada',  'mensagem' => 'Hora de registrar sua entrada!'],
            ['horario' => $repouso, 'tipo' => 'repouso',  'mensagem' => 'Hora de registrar sua saída para o intervalo!'],
            ['horario' => $retorno, 'tipo' => 'retorno',  'mensagem' => 'Hora de registrar seu retorno do intervalo!'],
            ['horario' => $saida,   'tipo' => 'saida',    'mensagem' => 'Hora de registrar sua saída!'],
        ];
    }
}
