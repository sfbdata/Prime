<?php

declare(strict_types=1);

namespace App\Backup\DTO;

/**
 * O que a montagem do ZIP devolve ao pedido (spec backup-e-exportacao-de-dados, seção 3): o nome da chave
 * `BACKUP_ZIP` no armazenamento, o tamanho, o sha256 do ZIP e as contagens que a tela e o LEIA-ME mostram.
 */
final readonly class ResultadoDoBackup
{
    public function __construct(
        public string $chaveArquivo,
        public int $tamanhoBytes,
        public string $sha256,
        public int $arquivosIncluidos,
        public int $arquivosFora,
    ) {
        if ('' === $chaveArquivo || $tamanhoBytes < 0 || 1 !== preg_match('/^[0-9a-f]{64}$/', $sha256)) {
            throw new \InvalidArgumentException('Resultado do backup inválido.');
        }
        if ($arquivosIncluidos < 0 || $arquivosFora < 0) {
            throw new \InvalidArgumentException('Contagens do backup não podem ser negativas.');
        }
    }
}
