<?php

declare(strict_types=1);

namespace App\AtualizacaoMonetaria\Service;

use App\AtualizacaoMonetaria\Enum\SerieIndice;

/**
 * Fotografia em memória das séries de índices, montada uma vez pelo repositório e injetada no motor
 * de cálculo (Parte 3).
 *
 * É o que mantém `CalculadoraAtualizacaoMonetaria` **sem I/O**: o motor não consulta banco nem rede,
 * recebe a tabela pronta e fica testável offline, com séries fabricadas à mão. Imutável — nada aqui
 * muda depois de construído.
 */
final class TabelaIndices
{
    /** @var array<string, array<string, string>> valor da série => ['Y-m-01' => variação percentual] */
    private array $variacoes = [];

    /**
     * Valida série, competência e valor na entrada — não confie em quem monta.
     *
     * O motor da Parte 3 vai fabricar tabelas à mão nos testes, e o modo de falha silencioso aqui é
     * caro: uma chave `'2020-01'` (sem o dia) faz `variacao()` devolver `null`, a correção sai
     * **zerada**, e o teste falha por "diferença de centavos" mandando quem depura caçar erro na
     * fórmula. Por isso a chave tem de ser uma data real no dia 1, e o valor passa pela mesma
     * canonização de `numeric(12,6)` que a entidade usa — assim o que o motor lê de uma tabela
     * fabricada tem exatamente a forma do que ele leria do banco.
     *
     * @param array<string, array<string, string>> $variacoes série => competência 'Y-m-01' => variação
     */
    public function __construct(array $variacoes = [])
    {
        foreach ($variacoes as $serie => $competencias) {
            $serieValida = SerieIndice::tryFrom((string) $serie);

            if ($serieValida === null) {
                throw new \InvalidArgumentException(sprintf('Série desconhecida na tabela: "%s".', $serie));
            }

            $normalizadas = [];
            foreach ($competencias as $competencia => $variacao) {
                $chave = self::exigirCompetencia((string) $competencia, $serieValida);
                $normalizadas[$chave] = VariacaoPercentual::canonizar($variacao);
            }

            ksort($normalizadas);
            $this->variacoes[$serieValida->value] = $normalizadas;
        }
    }

    private static function exigirCompetencia(string $competencia, SerieIndice $serie): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-01$/', $competencia, $partes) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Competência "%s" da série %s deve estar no formato AAAA-MM-01.',
                $competencia,
                $serie->value,
            ));
        }

        if (checkdate((int) $partes[2], 1, (int) $partes[1]) === false) {
            throw new \InvalidArgumentException(sprintf(
                'Competência "%s" da série %s não existe no calendário.',
                $competencia,
                $serie->value,
            ));
        }

        return $competencia;
    }

    /** Variação percentual do mês, ou null se a competência não foi publicada/importada. */
    public function variacao(SerieIndice $serie, \DateTimeImmutable $competencia): ?string
    {
        return $this->variacoes[$serie->value][self::chave($competencia)] ?? null;
    }

    public function temCompetencia(SerieIndice $serie, \DateTimeImmutable $competencia): bool
    {
        return $this->variacao($serie, $competencia) !== null;
    }

    /**
     * Última competência disponível da série. É a guarda do índice não publicado (spec §8): o INPC
     * de um mês só sai por volta do dia 7–10 do mês seguinte, e a calculadora recusa data final
     * além disso em vez de extrapolar ou repetir o último índice.
     */
    public function ultimaCompetencia(SerieIndice $serie): ?\DateTimeImmutable
    {
        $competencias = $this->competencias($serie);

        if ($competencias === []) {
            return null;
        }

        return self::data((string) end($competencias));
    }

    public function primeiraCompetencia(SerieIndice $serie): ?\DateTimeImmutable
    {
        $competencias = $this->competencias($serie);

        if ($competencias === []) {
            return null;
        }

        return self::data($competencias[0]);
    }

    /**
     * Competências da série em ordem crescente, no formato 'Y-m-01'.
     *
     * @return list<string>
     */
    public function competencias(SerieIndice $serie): array
    {
        return array_keys($this->variacoes[$serie->value] ?? []);
    }

    public function quantidade(SerieIndice $serie): int
    {
        return \count($this->variacoes[$serie->value] ?? []);
    }

    public function vazia(): bool
    {
        foreach (SerieIndice::cases() as $serie) {
            if ($this->quantidade($serie) > 0) {
                return false;
            }
        }

        return true;
    }

    private static function chave(\DateTimeImmutable $competencia): string
    {
        return $competencia->format('Y-m-01');
    }

    private static function data(string $chave): \DateTimeImmutable
    {
        return new \DateTimeImmutable($chave . ' 00:00:00');
    }
}
