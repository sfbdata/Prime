<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\NovoArquivo;

/**
 * Copia um arquivo guardado para uma chave nova (o "Duplicar" e a logo padrão): cada registro fica com o próprio
 * arquivo, e apagar um nunca apaga o do outro.
 */
final readonly class CopiaDeArquivo
{
    public function __construct(
        private ArmazenamentoDeArquivos $armazenamento,
    ) {
    }

    /**
     * @param callable(string): NovoArquivo $destino recebe a extensão da origem
     */
    public function copiar(ChaveDeArquivo $origem, callable $destino): ChaveDeArquivo
    {
        $extensao = strtolower(pathinfo($origem->nome, \PATHINFO_EXTENSION));
        $stream = $this->armazenamento->abrir($origem);

        try {
            return $this->armazenamento->gravar($destino($extensao !== '' ? $extensao : 'bin'), FonteDeConteudo::deStream($stream))->chave;
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
