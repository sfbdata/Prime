<?php

declare(strict_types=1);

namespace App\Inteligencia\Exception;

/**
 * A lista de caminhos do "Sugerir agrupamento com IA" não serve: vazia, com mais caminhos que o teto, ou com um
 * caminho vazio ou que não é texto. O controller responde 422; nada foi enviado ao provedor nem contado na cota.
 */
final class CaminhosDoLoteInvalidosException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('A lista de arquivos do lote não é válida. Escolha a pasta de novo.');
    }
}
