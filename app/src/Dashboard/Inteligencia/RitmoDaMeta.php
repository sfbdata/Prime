<?php

declare(strict_types=1);

namespace App\Dashboard\Inteligencia;

use App\Dashboard\DTO\MetaDePastasDoMesOutput;

/**
 * O ritmo da meta de pastas do mês, para o selo, o gauge e o "Ritmo necessário" do painel
 * (dc L1198-1218). Porte de `indicadores()` e `classificarRitmo()` (bluejus-intelligence.js
 * L25-56) sobre a meta que o escritório definiu, em DIAS ÚTEIS (decisão do dono).
 *
 * Só existe com meta definida e o painel no mês inteiro, sem responsável nem cargo: com outro
 * recorte o número do card não é o do escritório e não se compara à meta (mesma régua do card).
 * Sem meta não há objetivo, e o painel não inventa um (o desenho calcula o objetivo a partir
 * de períodos anteriores, que o sistema não guarda).
 */
final readonly class RitmoDaMeta
{
    /** Fração do mês abaixo da qual o desvio ainda é recuperável (L52). */
    private const INICIO_FRACAO = 0.2;

    /** Dias úteis restantes a partir dos quais o mês está na reta final (L54). */
    private const FINAL_RESTANTES = 5;

    public function __construct(
        public EstadoDoRitmo $estado,
        public int $meta,
        public int $realizado,
        public int $diaUtil,
        public int $diasUteisTotal,
        public float $ritmoAtual,
        /** Pastas por dia útil que faltam para a meta; nulo quando acabou o mês sem bater. */
        public ?float $ritmoNecessario,
    ) {
    }

    public static function de(MetaDePastasDoMesOutput $meta): ?self
    {
        if (!$meta->visivel || $meta->meta === null || $meta->meta <= 0) {
            return null;
        }

        $faltam     = max(0, $meta->meta - $meta->realizado);
        $restantes  = max(0, $meta->diasUteisTotal - $meta->diasUteisDecorridos);
        $ritmoAtual = $meta->diasUteisDecorridos > 0 ? $meta->realizado / $meta->diasUteisDecorridos : 0.0;
        $necessario = match (true) {
            $restantes > 0 => $faltam / $restantes,
            $faltam === 0  => 0.0,
            default        => null,
        };

        return new self(
            self::classificar($faltam, $restantes, $meta->diasUteisDecorridos / max(1, $meta->diasUteisTotal), $ritmoAtual, $necessario),
            $meta->meta,
            $meta->realizado,
            $meta->diasUteisDecorridos,
            $meta->diasUteisTotal,
            $ritmoAtual,
            $necessario,
        );
    }

    /** O necessário supera o atual: o selo e o número saem em vermelho (dc `necStyle`). */
    public function precisaAcelerar(): bool
    {
        return $this->ritmoNecessario !== null && $this->ritmoNecessario > $this->ritmoAtual;
    }

    private static function classificar(int $faltam, int $restantes, float $fracao, float $atual, ?float $necessario): EstadoDoRitmo
    {
        if ($faltam === 0) {
            return EstadoDoRitmo::Adequado;
        }
        if ($restantes === 0 || $necessario === null) {
            return EstadoDoRitmo::Critico;
        }

        $relacao = $necessario > 0 ? $atual / $necessario : 2.0;
        $estado  = match (true) {
            $relacao >= 1    => EstadoDoRitmo::Adequado,
            $relacao >= 0.85 => EstadoDoRitmo::Atencao,
            $relacao >= 0.65 => EstadoDoRitmo::Abaixo,
            default          => EstadoDoRitmo::Critico,
        };

        // Início do mês: o desvio ainda é recuperável, suaviza um nível (L52).
        if ($fracao < self::INICIO_FRACAO && $estado !== EstadoDoRitmo::Adequado) {
            $estado = $estado === EstadoDoRitmo::Critico ? EstadoDoRitmo::Abaixo : EstadoDoRitmo::Atencao;
        }
        // Reta final: a tolerância é menor, agrava um nível (L54).
        if ($restantes <= self::FINAL_RESTANTES && $estado === EstadoDoRitmo::Atencao) {
            $estado = EstadoDoRitmo::Abaixo;
        }

        return $estado;
    }
}
