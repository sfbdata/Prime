<?php

declare(strict_types=1);

namespace App\Contribua\DTO;

/**
 * Uma conta (escritório) no relatório de contribuições do Master: só o nome, se foi excluída, a soma, a
 * quantidade e a data do último apoio confirmado. Nada de e-mail, CPF ou CNPJ.
 */
final readonly class ContribuicoesDaConta
{
    public function __construct(
        public string $escritorio,
        public bool $excluido,
        public int $totalCentavos,
        public int $quantidade,
        public \DateTimeImmutable $ultimoApoio,
    ) {
    }
}
