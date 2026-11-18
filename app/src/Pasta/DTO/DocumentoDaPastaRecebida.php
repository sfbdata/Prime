<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * O documento da pasta recebida que vai ser entregue (rota `expediente_recebida_documento`): só o que monta a chave do
 * arquivo (escritório e nome no armazenamento) e o nome para o navegador. Nunca a entidade.
 */
final readonly class DocumentoDaPastaRecebida
{
    public function __construct(
        public int $id,
        public int $tenantId,
        public string $caminho,
        public string $nomeOriginal,
    ) {
    }
}
