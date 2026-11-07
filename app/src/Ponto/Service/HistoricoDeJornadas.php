<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Ponto\Entity\JornadaColaborador;

/**
 * As versões da jornada pessoal de uma pessoa NUM escritório, cada uma com a data em que passa a valer
 * (spec `docs/specs/ponto-jornada-vigencia.md` §3.1). Quem varre dias (folha, saldo) pergunta a versão de
 * cada dia aqui, em vez de aplicar a jornada atual a todo o passado.
 *
 * Regras: cada versão vale da sua data até o dia anterior à seguinte; a mais recente vale indefinidamente; a
 * mais antiga vale também para os dias anteriores à sua data, para nenhum dia que tinha jornada ficar sem ela.
 */
final class HistoricoDeJornadas
{
    /** @param non-empty-list<JornadaColaborador> $versoes da mais antiga para a mais nova */
    private function __construct(private readonly array $versoes)
    {
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

        return new self($lista);
    }

    /** Uma jornada só, valendo para todos os dias: é como a folha funcionava antes das versões. */
    public static function deUma(JornadaColaborador $jornada): self
    {
        return new self([$jornada]);
    }

    /** A versão que vale no dia (só a data conta; a hora é ignorada). */
    public function vigenteEm(\DateTimeInterface $dia): JornadaColaborador
    {
        $data = $dia->format('Y-m-d');
        $vigente = $this->versoes[0];
        foreach ($this->versoes as $versao) {
            if ($versao->getValeAPartirDe()->format('Y-m-d') > $data) {
                break;
            }
            $vigente = $versao;
        }

        return $vigente;
    }

    /** @return non-empty-list<JornadaColaborador> da mais antiga para a mais nova */
    public function versoes(): array
    {
        return $this->versoes;
    }
}
