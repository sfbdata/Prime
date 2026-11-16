<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/**
 * A tela "Estatísticas de todas as votações" do Master: os KPIs (sobre as linhas já filtradas por categoria) e uma
 * linha por pauta. Os KPIs de ocorrências e avaliações (W2-7b) contam por ASSEMBLEIA: a assembleia com várias pautas tem
 * várias linhas com os mesmos números, e cada uma entra uma vez na soma.
 */
final readonly class EstatisticasDoVotoOutput
{
    /** @param list<EstatisticaDaVotacao> $linhas */
    public function __construct(public array $linhas)
    {
    }

    public function votacoes(): int
    {
        return count($this->linhas);
    }

    public function realizadas(): int
    {
        return count($this->realizadasLista());
    }

    public function emAndamento(): int
    {
        return count(array_filter($this->linhas, static fn (EstatisticaDaVotacao $l): bool => 'aberta' === $l->situacao));
    }

    public function aptosNasRealizadas(): int
    {
        return array_sum(array_map(static fn (EstatisticaDaVotacao $l): int => $l->aptos, $this->realizadasLista()));
    }

    public function votantesNasRealizadas(): int
    {
        return array_sum(array_map(static fn (EstatisticaDaVotacao $l): int => $l->votantes, $this->realizadasLista()));
    }

    /** Votantes ÷ aptos das realizadas, como o desenho ("0%" sem aptos). */
    public function participacaoNasRealizadas(): string
    {
        $aptos = $this->aptosNasRealizadas();

        return EstatisticaDaVotacao::formatarPercentual($aptos > 0 ? round($this->votantesNasRealizadas() / $aptos * 100, 1) : 0.0);
    }

    /** Realizadas em que o quórum é exigido (o "y" do "x de y"). */
    public function comQuorumExigido(): int
    {
        return count($this->comQuorum());
    }

    public function quorumAtingido(): int
    {
        return count(array_filter($this->comQuorum(), static fn (EstatisticaDaVotacao $l): bool => EstatisticaDaVotacao::QUORUM_ATINGIDO === $l->quorum));
    }

    /** KPI "Ocorrências": as registradas, por assembleia. */
    public function ocorrencias(): int
    {
        return $this->somarPorAssembleia(static fn (EstatisticaDaVotacao $l): int => $l->ocorrencias);
    }

    /** KPI "Avaliações pendentes". */
    public function avaliacoesPendentes(): int
    {
        return $this->somarPorAssembleia(static fn (EstatisticaDaVotacao $l): int => $l->pendentes);
    }

    /** KPI "Inaptidões mantidas". */
    public function inaptidoesMantidas(): int
    {
        return $this->somarPorAssembleia(static fn (EstatisticaDaVotacao $l): int => $l->mantidas);
    }

    /** KPI "Tornados aptos". */
    public function tornadosAptos(): int
    {
        return $this->somarPorAssembleia(static fn (EstatisticaDaVotacao $l): int => $l->tornadosAptos);
    }

    /** @param callable(EstatisticaDaVotacao): int $campo */
    private function somarPorAssembleia(callable $campo): int
    {
        return array_sum(array_map($campo, array_filter($this->linhas, static fn (EstatisticaDaVotacao $l): bool => $l->primeiraDaAssembleia)));
    }

    /** @return list<EstatisticaDaVotacao> */
    private function realizadasLista(): array
    {
        return array_values(array_filter($this->linhas, static fn (EstatisticaDaVotacao $l): bool => $l->realizada()));
    }

    /** @return list<EstatisticaDaVotacao> */
    private function comQuorum(): array
    {
        return array_values(array_filter($this->realizadasLista(), static fn (EstatisticaDaVotacao $l): bool => EstatisticaDaVotacao::QUORUM_NAO_EXIGIDO !== $l->quorum));
    }
}
