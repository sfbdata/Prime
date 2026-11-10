<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

use App\Ponto\Enum\SituacaoDeHojeNaEquipe;

/**
 * O painel da aba Equipe da /ponto (desenho 16 L210 a L219): os indicadores, a equipe e as justificativas a analisar.
 * Os indicadores somam a própria tabela e a lista, como no desenho (`eqKpi` L780).
 */
final readonly class PainelDaEquipeOutput
{
    /**
     * @param list<MembroDaEquipeOutput>        $membros        quem vê primeiro, quando está na equipe; depois por nome
     * @param list<JustificativaAAnalisarOutput> $justificativas
     */
    public function __construct(
        public array $membros,
        public array $justificativas,
    ) {
    }

    /**
     * Os cinco quadros, na ordem e no tom do desenho: o tom é o sufixo da classe da cor na tela.
     *
     * @return list<array{rotulo: string, valor: int, tom: string}>
     */
    public function indicadores(): array
    {
        return [
            ['rotulo' => 'Em jornada', 'valor' => $this->naSituacao(SituacaoDeHojeNaEquipe::EmJornada), 'tom' => 'jornada'],
            ['rotulo' => 'Em intervalo', 'valor' => $this->naSituacao(SituacaoDeHojeNaEquipe::EmIntervalo), 'tom' => 'intervalo'],
            ['rotulo' => 'Sem registro', 'valor' => $this->naSituacao(SituacaoDeHojeNaEquipe::SemRegistro), 'tom' => 'sem'],
            ['rotulo' => 'Pendências', 'valor' => array_sum(array_map(static fn (MembroDaEquipeOutput $m): int => $m->pendencias, $this->membros)), 'tom' => 'pendencias'],
            ['rotulo' => 'A analisar', 'valor' => count($this->justificativas), 'tom' => 'analisar'],
        ];
    }

    private function naSituacao(SituacaoDeHojeNaEquipe $situacao): int
    {
        return count(array_filter($this->membros, static fn (MembroDaEquipeOutput $m): bool => $m->situacao === $situacao));
    }
}
