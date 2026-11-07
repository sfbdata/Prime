<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Ponto\Entity\JornadaColaborador;

/**
 * As versões da jornada pessoal de uma pessoa NUM escritório, cada uma com a data em que passa a valer
 * (spec `docs/specs/ponto-jornada-vigencia.md` §3.1). Quem varre dias (folha, saldo) pergunta a versão de
 * cada dia aqui, em vez de aplicar a jornada atual a todo o passado.
 *
 * Regras: cada versão vale da sua data até o dia anterior à seguinte; a mais recente vale indefinidamente. Antes da
 * mais antiga, ela vale também para trás só quando alguma versão `cobreDiasAnteriores()` (as jornadas que existiam
 * antes das versões, §10.1); sem isso, aqueles dias ficam sem jornada pessoal, como eram antes de ela existir.
 */
final class HistoricoDeJornadas
{
    /**
     * @param non-empty-list<JornadaColaborador> $versoes         da mais antiga para a mais nova
     * @param JornadaColaborador|null            $antesDaPrimeira o que vale antes da primeira quando ela não cobre para trás
     */
    private function __construct(
        private readonly array $versoes,
        private readonly bool $cobreParaTras,
        private readonly ?JornadaColaborador $antesDaPrimeira = null,
    ) {
    }

    /**
     * @param iterable<JornadaColaborador> $versoes em qualquer ordem
     *
     * @return self|null sem nenhuma versão, nenhum histórico (a pessoa segue só o padrão do escritório)
     */
    public static function das(iterable $versoes): ?self
    {
        $lista = [];
        foreach ($versoes as $versao) {
            $lista[] = $versao;
        }
        if ($lista === []) {
            return null;
        }

        usort(
            $lista,
            static fn (JornadaColaborador $a, JornadaColaborador $b): int => $a->getValeAPartirDe() <=> $b->getValeAPartirDe(),
        );

        $cobreParaTras = false;
        foreach ($lista as $versao) {
            $cobreParaTras = $cobreParaTras || $versao->cobreDiasAnteriores();
        }

        return new self($lista, $cobreParaTras);
    }

    /** Uma jornada só, valendo para todos os dias: é como a folha funcionava antes das versões. */
    public static function deUma(JornadaColaborador $jornada): self
    {
        return new self([$jornada], true);
    }

    /**
     * A versão que vale no dia (só a data conta; a hora é ignorada). Antes da primeira que não cobre para trás, a
     * jornada de `comJornadaAntesDaPrimeira()`, ou `null`.
     */
    public function vigenteEm(\DateTimeInterface $dia): ?JornadaColaborador
    {
        $data = $dia->format('Y-m-d');
        $vigente = $this->cobreParaTras ? $this->versoes[0] : $this->antesDaPrimeira;
        foreach ($this->versoes as $versao) {
            if ($versao->getValeAPartirDe()->format('Y-m-d') > $data) {
                break;
            }
            $vigente = $versao;
        }

        return $vigente;
    }

    /**
     * O histórico depois de excluir uma versão: os dias dela passam para a vizinha, e os dias de antes continuam
     * cobertos se estavam (a mais antiga que sobra herda a marca, §10.2.4).
     *
     * @return self|null sem a única versão, nenhum histórico
     */
    public function semA(JornadaColaborador $excluida): ?self
    {
        $restantes = array_values(array_filter(
            $this->versoes,
            static fn (JornadaColaborador $versao): bool => $versao !== $excluida,
        ));

        return $restantes === [] ? null : new self($restantes, $this->cobreParaTras, $this->antesDaPrimeira);
    }

    /**
     * O mesmo histórico, com `$jornada` valendo nos dias antes da primeira versão que não cobre para trás. A tela
     * /ponto do colaborador calcula o dia de quem não tem jornada pessoal com uma jornada vazia (a padrão do
     * escritório); com isto, agendar a primeira jornada não tira o saldo dos dias de antes dela nessa tela.
     */
    public function comJornadaAntesDaPrimeira(JornadaColaborador $jornada): self
    {
        return new self($this->versoes, $this->cobreParaTras, $jornada);
    }

    /** @return non-empty-list<JornadaColaborador> da mais antiga para a mais nova */
    public function versoes(): array
    {
        return $this->versoes;
    }
}
