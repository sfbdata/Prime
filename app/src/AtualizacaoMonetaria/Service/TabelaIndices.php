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
     * @param array<string, array<string, string>> $variacoes série => competência 'Y-m-01' => variação
     */
    public function __construct(array $variacoes = [])
    {
        foreach ($variacoes as $serie => $competencias) {
            $serieValida = SerieIndice::tryFrom((string) $serie);

            if ($serieValida === null) {
                throw new \InvalidArgumentException(sprintf('Série desconhecida na tabela: "%s".', $serie));
            }

            ksort($competencias);
            $this->variacoes[$serieValida->value] = $competencias;
        }
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
