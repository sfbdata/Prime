<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\Entity\MetricaDiariaDoSistema;
use App\Master\Repository\MetricaDiariaDoSistemaRepository;
use App\Master\Repository\PainelMasterRepository;
use Psr\Clock\ClockInterface;

/**
 * A foto do dia das métricas do gráfico Crescimento (desenho 05 - Perfis 1.2, VS 03.11.26, visão "Diário"):
 * quantas contas, usuários e pastas existem agora e quantos bytes de arquivo, com as MESMAS regras do gráfico
 * mensal ({@see PainelMasterRepository::entradasPorMes()}). Roda todo dia pela tarefa agendada
 * `app:master:retratar-metricas`; rodar de novo no mesmo dia só regrava a foto.
 */
final class RetratarMetricasDoDiaUseCase
{
    public function __construct(
        private readonly PainelMasterRepository $painel,
        private readonly MetricaDiariaDoSistemaRepository $fotos,
        private readonly ClockInterface $relogio,
    ) {
    }

    /**
     * @return array<string, int> métrica => valor gravado
     */
    public function executar(): array
    {
        $agora    = $this->relogio->now();
        $entradas = $this->painel->entradasPorDia();

        $valores = [];
        foreach (MetricaDiariaDoSistema::METRICAS as $metrica) {
            $valores[$metrica] = (int) round(array_sum($entradas[$metrica]));
        }
        $this->fotos->gravarDoDia($agora->setTime(0, 0), $valores, $agora);

        return $valores;
    }
}
