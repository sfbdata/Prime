<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * A lista que a reconciliação encontrou não é a que o dono aprovou (SPEC §17.11).
 *
 * 🔑 **É lançada DEPOIS das alterações e DENTRO da transação, de propósito.** A contagem só é conhecida
 * ao fim da varredura, e conferir antes exigiria percorrer o universo duas vezes. Lançar aqui faz o
 * `wrapInTransaction` derrubar tudo — nada é gravado —, o que dá a mesma garantia por um caminho mais
 * simples. Se um dia a checagem passar a acontecer antes da escrita, esta ressalva perde o sentido e
 * deve sair junto.
 */
final class UniversoDaListaMudouException extends \RuntimeException
{
    public function __construct(
        public readonly int $dividasEsperadas,
        public readonly int $dividasEncontradas,
        public readonly int $totalEsperado,
        public readonly int $totalEncontrado,
    ) {
        parent::__construct(sprintf(
            'A lista mudou desde a aprovação: esperadas %d dívida(s) / %d centavos, encontradas %d / %d. '
            . 'Nada foi gravado. Rode a régua de novo, leve a lista nova para aprovação e repita.',
            $dividasEsperadas,
            $totalEsperado,
            $dividasEncontradas,
            $totalEncontrado,
        ));
    }
}
