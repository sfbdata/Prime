<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Outbox;

use App\Shared\Armazenamento\ChaveDeArquivo;

/** Uma linha `enviar` reivindicada pelo worker: o que enviar, de qual versão (SHA) e em qual tentativa. */
final readonly class ItemDeReplicacao
{
    public function __construct(
        public int $id,
        public ChaveDeArquivo $chave,
        public string $chaveRemota,
        public string $sha256,
        public int $tamanhoBytes,
        public string $mimeType,
        public int $tentativas,
        /** Token da posse desta reivindicação: só ele conclui, reagenda ou renova a linha. */
        public string $posse,
    ) {
    }
}
