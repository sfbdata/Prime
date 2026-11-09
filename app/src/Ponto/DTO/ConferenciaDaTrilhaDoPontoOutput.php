<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

/**
 * O resultado da conferência da trilha de auditoria de um escritório (E9): íntegra, alterada (com a sequência do
 * primeiro evento que não confere), trecho sem selo (as faixas de eventos sem selo, que não podem ser conferidos; o
 * resto confere) ou não selada (o servidor não tem o segredo: nada pode ser conferido). O texto é o do cabeçalho da aba
 * Auditoria. O texto do trecho não diz por que os eventos estão sem selo: o sistema não sabe se foram gravados sem o
 * segredo ou se o selo foi tirado depois.
 */
final class ConferenciaDaTrilhaDoPontoOutput
{
    public const INTEGRA = 'integra';
    public const ALTERADA = 'alterada';
    public const NAO_SELADA = 'nao_selada';

    /** Há eventos sem selo, que não podem ser conferidos; os selados conferem. */
    public const TRECHO_SEM_SELO = 'trecho_sem_selo';

    /** Não selada porque o servidor não tem `PONTO_HMAC_SECRET`: nada pode ser conferido. */
    public const SEM_SEGREDO = 'sem_segredo';

    /** Quantas faixas o texto do cabeçalho cita; as outras viram "e mais N trechos". */
    private const FAIXAS_NO_TEXTO = 3;

    /**
     * @param list<array{0: int, 1: int}> $trechosSemSelo faixas [primeiro, último] dos eventos sem selo, em ordem
     */
    private function __construct(
        public readonly string $situacao,
        public readonly ?int $sequenciaAlterada = null,
        public readonly ?string $motivoSemSelo = null,
        public readonly array $trechosSemSelo = [],
        private readonly bool $temSelados = false,
    ) {
    }

    public static function integra(): self
    {
        return new self(self::INTEGRA);
    }

    public static function alterada(int $sequencia): self
    {
        return new self(self::ALTERADA, sequenciaAlterada: $sequencia);
    }

    public static function semSegredo(): self
    {
        return new self(self::NAO_SELADA, motivoSemSelo: self::SEM_SEGREDO);
    }

    /**
     * @param list<array{0: int, 1: int}> $trechos   faixas [primeiro, último] dos eventos sem selo
     * @param bool                        $temSelados se a cadeia tem eventos selados (todos conferiram)
     */
    public static function trechoSemSelo(array $trechos, bool $temSelados): self
    {
        return new self(self::TRECHO_SEM_SELO, trechosSemSelo: $trechos, temSelados: $temSelados);
    }

    public function texto(): string
    {
        return match ($this->situacao) {
            self::INTEGRA => 'Cadeia de eventos íntegra: cada evento é selado com o anterior. Registros originais nunca são sobrescritos.',
            self::ALTERADA => sprintf('Atenção: a cadeia de eventos foi alterada. O primeiro evento que não confere é o nº %d.', $this->sequenciaAlterada),
            self::TRECHO_SEM_SELO => $this->textoDoTrecho(),
            default => 'Cadeia de eventos sem selo: a chave do selo não está configurada no servidor, então os eventos não podem ser conferidos.',
        };
    }

    private function textoDoTrecho(): string
    {
        $faixas = array_map(
            static fn (array $faixa): string => $faixa[0] === $faixa[1] ? 'nº ' . $faixa[0] : sprintf('nº %d a %d', $faixa[0], $faixa[1]),
            \array_slice($this->trechosSemSelo, 0, self::FAIXAS_NO_TEXTO),
        );
        $resto = \count($this->trechosSemSelo) - \count($faixas);
        if ($resto > 0) {
            $faixas[] = sprintf('mais %d %s', $resto, $resto === 1 ? 'trecho' : 'trechos');
        }

        $umSo  = \count($this->trechosSemSelo) === 1 && $this->trechosSemSelo[0][0] === $this->trechosSemSelo[0][1];
        $lista = \count($faixas) === 1 ? $faixas[0] : implode(', ', \array_slice($faixas, 0, -1)) . ' e ' . $faixas[\count($faixas) - 1];
        $texto = $umSo
            ? sprintf('O evento %s está sem selo e não pode ser conferido.', $lista)
            : sprintf('Os eventos %s estão sem selo e não podem ser conferidos.', $lista);

        return $this->temSelados ? $texto . ' Os demais eventos conferem com o selo.' : $texto;
    }
}
