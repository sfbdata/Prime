<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * O que chegou numa pasta nesta captação, pronto para o sino e o e-mail (spec
 * push-avisar-quando-chegar §3). Um aviso por pasta por captação: as movimentações agrupadas, da
 * mais recente para a mais antiga.
 *
 * Mensagem: "Nova movimentação na Pasta {nº}: {tipo} de {dd/mm/aaaa}" com uma, ou
 * "N novas movimentações na Pasta {nº}" com mais.
 */
final readonly class AvisoDaPastaOutput
{
    /** O e-mail lista no máximo estas; o resto vira "e mais N" (o sino só conta). */
    public const MAXIMO_NO_EMAIL = 20;

    /** @param non-empty-list<MovimentacaoDoAvisoOutput> $movimentacoes */
    public function __construct(
        public int $pastaId,
        public string $numeroDaPasta,
        public array $movimentacoes,
    ) {
    }

    public function mensagem(): string
    {
        $quantas = \count($this->movimentacoes);
        if ($quantas > 1) {
            return sprintf('%d novas movimentações na Pasta %s', $quantas, $this->numeroDaPasta);
        }

        $unica = $this->movimentacoes[0];

        return sprintf('Nova movimentação na Pasta %s: %s', $this->numeroDaPasta, $unica->tipo)
            . ($unica->data !== null ? ' de ' . $unica->data : '');
    }

    /** @return list<MovimentacaoDoAvisoOutput> */
    public function movimentacoesDoEmail(): array
    {
        return \array_slice($this->movimentacoes, 0, self::MAXIMO_NO_EMAIL);
    }

    public function restantesForaDoEmail(): int
    {
        return max(0, \count($this->movimentacoes) - self::MAXIMO_NO_EMAIL);
    }
}
