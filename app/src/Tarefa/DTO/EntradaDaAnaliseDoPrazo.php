<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

/** O que a análise do prazo da meta recebe: o texto da meta, o dia de hoje e as datas que a pessoa informou. */
final readonly class EntradaDaAnaliseDoPrazo
{
    public function __construct(
        /** Título e descrição da meta, separados por quebra de linha (o tipo sai primeiro do título). */
        public string $texto,
        public \DateTimeImmutable $hoje,
        public ?\DateTimeImmutable $publicacao = null,
        public ?\DateTimeImmutable $ciencia = null,
        /** Prazo jurídico informado pela pessoa (vale sobre qualquer estimativa). */
        public ?\DateTimeImmutable $prazoJuridicoInformado = null,
        /** A pessoa confirmou a análise com esse prazo jurídico. */
        public bool $confirmado = false,
    ) {
    }
}
