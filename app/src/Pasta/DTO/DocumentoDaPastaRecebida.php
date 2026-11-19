<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Shared\Armazenamento\ChaveDeArquivo;

/**
 * O documento da pasta recebida que vai ser entregue (rota `expediente_recebida_documento`): a chave do arquivo, já
 * montada da entidade dentro da janela sem filtro (`ChavesDePasta::documento`), e o nome para o navegador. Nunca a
 * entidade.
 */
final readonly class DocumentoDaPastaRecebida
{
    public function __construct(
        public int $id,
        public ChaveDeArquivo $chave,
        public string $nomeOriginal,
    ) {
    }
}
