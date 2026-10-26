<?php

declare(strict_types=1);

namespace App\Dashboard\DTO;

/**
 * A meta de pastas do mês na tela (DASH-016; desenho `nivelador`): o realizado do mês, a meta vigente e o
 * ritmo esperado.
 *
 * `meta` nulo = o escritório está sem meta (a tela mostra "Definir meta do mês" a quem pode). O ritmo
 * conta DIAS ÚTEIS (decisão provisória do coordenador, o dono pode rever): `diasUteisTotal` do mês e
 * `diasUteisDecorridos` até hoje (hoje incluído, se útil).
 */
final class MetaDePastasDoMesOutput
{
    public function __construct(
        /** Meta vigente no mês; nulo = sem meta. */
        public readonly ?int $meta,
        /** Mês em que a meta vigente começou ('Y-m'); nulo sem meta. Antes do mês corrente = herdada. */
        public readonly ?string $desde,
        /** Mês de referência ('Y-m'). */
        public readonly string $mes,
        /** Pastas criadas no mês: o número do card "Pastas criadas". */
        public readonly int $realizado,
        public readonly int $diasUteisTotal,
        public readonly int $diasUteisDecorridos,
        /** O painel está no mês inteiro, sem responsável nem cargo (`mesLimpo` do desenho): só então o card mostra a meta. */
        public readonly bool $visivel = true,
    ) {
    }

    public function temMeta(): bool
    {
        return $this->meta !== null;
    }

    public function herdada(): bool
    {
        return $this->desde !== null && $this->desde < $this->mes;
    }

    /** Onde a equipe deveria estar hoje: meta × dia útil ÷ total de dias úteis, arredondado (o `esperado` do desenho). */
    public function esperado(): int
    {
        if ($this->meta === null || $this->diasUteisTotal === 0) {
            return 0;
        }

        return (int) round($this->meta * $this->diasUteisDecorridos / $this->diasUteisTotal);
    }

    public function batida(): bool
    {
        return $this->meta !== null && $this->realizado >= $this->meta;
    }

    public function noRitmo(): bool
    {
        return $this->realizado >= $this->esperado();
    }

    public function faltam(): int
    {
        return $this->meta === null ? 0 : max(0, $this->meta - $this->realizado);
    }

    public function diasUteisRestantes(): int
    {
        return max(0, $this->diasUteisTotal - $this->diasUteisDecorridos);
    }

    /** Pastas por dia útil que faltam para fechar a meta (com 0 dia útil restante, tudo o que falta). */
    public function porDia(): float
    {
        $restam = $this->diasUteisRestantes();

        return $restam > 0 ? $this->faltam() / $restam : (float) $this->faltam();
    }

    /** Por semana: a semana útil tem 5 dias (o desenho, em dias corridos, multiplica por 7). */
    public function porSemana(): int
    {
        return (int) ceil($this->porDia() * 5);
    }

    public function percentual(): float
    {
        return $this->meta === null || $this->meta === 0 ? 0.0 : min(100.0, $this->realizado / $this->meta * 100);
    }

    public function percentualEsperado(): float
    {
        return $this->meta === null || $this->meta === 0 ? 0.0 : min(100.0, $this->esperado() / $this->meta * 100);
    }
}
