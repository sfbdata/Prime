<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

/** O que o modal "Nova meta" manda para a análise do prazo: texto da meta e as datas que a pessoa informou. */
final readonly class AnalisarPrazoDaMetaInput
{
    public function __construct(
        public string $titulo,
        public string $descricao,
        public ?\DateTimeImmutable $publicacao = null,
        public ?\DateTimeImmutable $ciencia = null,
        public ?\DateTimeImmutable $prazoJuridicoInformado = null,
        public bool $confirmado = false,
    ) {
    }

    /** `aaaa-mm-dd` do `<input type="date">`; vazio ou data impossível (31/02) vira null. */
    public static function dataDoCampo(mixed $valor): ?\DateTimeImmutable
    {
        $texto = trim((string) $valor);
        $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $texto);

        return false !== $data && $data->format('Y-m-d') === $texto ? $data : null;
    }
}
