<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

/**
 * O resumo de UMA rodada de `app:documentos:apagar-duplicados`. `ultimaPasta` é o cursor da próxima
 * fatia (`--a-partir-de`); `gruposAFrente` é quanto ainda há em pastas depois dele (zero = acabou).
 * `bytesALiberar` é o tamanho das cópias enviadas à lixeira: só vira espaço em disco quando a purga
 * agendada (30 dias) apagar o arquivo físico, e só se nenhuma outra linha usar o mesmo arquivo.
 */
final readonly class ResultadoDaLimpezaDeDuplicados
{
    /**
     * @param list<DecisaoSobreGrupoDeDuplicados> $decisoes todos os grupos vistos, na ordem
     */
    public function __construct(
        public bool $gravou,
        public array $decisoes,
        public int $pastasTocadas,
        public ?int $ultimaPasta,
        public int $gruposAFrente,
    ) {
    }

    public function gruposVistos(): int
    {
        return \count($this->decisoes);
    }

    public function copiasEnviadas(): int
    {
        return array_sum(array_map(static fn (DecisaoSobreGrupoDeDuplicados $d): int => \count($d->enviadosIds), $this->decisoes));
    }

    public function bytesALiberar(): int
    {
        return array_sum(array_map(static fn (DecisaoSobreGrupoDeDuplicados $d): int => $d->bytesEnviados, $this->decisoes));
    }

    /** @return list<DecisaoSobreGrupoDeDuplicados> */
    public function pulados(): array
    {
        return array_values(array_filter($this->decisoes, static fn (DecisaoSobreGrupoDeDuplicados $d): bool => $d->foiPulado()));
    }
}
