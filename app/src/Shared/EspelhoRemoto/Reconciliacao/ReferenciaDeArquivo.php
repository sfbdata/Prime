<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Reconciliacao;

use App\Shared\Armazenamento\ChaveDeArquivo;

/** Uma linha do banco que aponta para um arquivo. `chave` nula: o valor guardado não vira chave. */
final readonly class ReferenciaDeArquivo
{
    public function __construct(
        public string $fonte,
        public int $idDaLinha,
        public ?ChaveDeArquivo $chave,
    ) {
    }
}
