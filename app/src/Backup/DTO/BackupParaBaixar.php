<?php

declare(strict_types=1);

namespace App\Backup\DTO;

use App\Shared\Armazenamento\ChaveDeArquivo;

/** O que o controller precisa para entregar o ZIP de um backup: a chave no armazenamento e o nome do download. */
final readonly class BackupParaBaixar
{
    public function __construct(
        public ChaveDeArquivo $chave,
        public string $nomeDoArquivo,
        public int $pedidoId,
        public ?int $tamanhoBytes,
    ) {
    }
}
