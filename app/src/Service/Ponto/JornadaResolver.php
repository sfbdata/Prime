<?php

namespace App\Service\Ponto;

use App\Entity\Auth\User;
use App\Entity\Ponto\JornadaTenant;

class JornadaResolver
{
    public function resolverMetaDia(User $user, \DateTimeInterface $data, ?JornadaTenant $jornadaTenant): int
    {
        $indiceDia = (int) $data->format('N');
        $jornada = $user->getJornadaColaborador();

        // Blocos do colaborador têm prioridade
        if ($jornada !== null && !$jornada->getBlocos()->isEmpty()) {
            foreach ($jornada->getBlocos() as $bloco) {
                if (in_array($indiceDia, $bloco->getDiasSemana(), true)) {
                    return $bloco->getMinutosBloco();
                }
            }
            // Colaborador tem blocos mas nenhum cobre este dia
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

        // Fallback legado: campos planos de JornadaColaborador
        if ($jornada !== null) {
            if ($indiceDia === 6) {
                return $jornada->getCargaHorariaSabado() ?? 0;
            }
            if (in_array($indiceDia, $jornada->getDiasSemana(), true)) {
                return $jornada->getCargaHorariaDiaria() ?? 0;
            }
        }

        return 0;
    }

    public function resolverAlertaHabilitado(User $user, ?JornadaTenant $jornadaTenant): bool
    {
        $jornada = $user->getJornadaColaborador();

        if ($jornada !== null) {
            return $jornada->isAlertaHabilitado();
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
        $jornada = $user->getJornadaColaborador();

        // Blocos do colaborador têm prioridade
        if ($jornada !== null && !$jornada->getBlocos()->isEmpty()) {
            foreach ($jornada->getBlocos() as $bloco) {
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
        if ($jornada !== null) {
            if ($indiceDia === 6 && in_array(6, $jornada->getDiasSemana(), true)) {
                return $this->batidasDeBloco($jornada->getEntradaSabado(), null, null, $jornada->getSaidaSabado());
            }
            if (in_array($indiceDia, $jornada->getDiasSemana(), true)) {
                return $this->batidasDeBloco($jornada->getEntrada1(), $jornada->getSaida1(), $jornada->getEntrada2(), $jornada->getSaida2());
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
