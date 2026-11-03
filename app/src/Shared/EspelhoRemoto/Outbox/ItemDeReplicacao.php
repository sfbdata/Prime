<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Outbox;

use App\Shared\Armazenamento\ChaveDeArquivo;

/**
 * Uma linha reivindicada pelo worker: o que enviar, de qual versão (SHA) e em qual tentativa, ou o
 * que apagar do espelho (`excluir`, frente D6; aí SHA e tamanho vêm vazios).
 */
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
        public string $operacao = OutboxDeReplicacao::OPERACAO_ENVIAR,
    ) {
    }

    public function ehExclusao(): bool
    {
        return $this->operacao === OutboxDeReplicacao::OPERACAO_EXCLUIR;
    }
}
