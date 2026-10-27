<?php

declare(strict_types=1);

namespace App\Dashboard\DTO;

/**
 * Uma linha da "Matriz de desempenho" (dc 01 L1113-1121): o eixo (`chave`), o estado em palavra
 * (`estado`: normal, atencao, elevada, baixa, melhorando, piorando, estavel, sem_base ou sem_registro)
 * e o tom da cor (`tom`: bom, atencao, ruim, info, neutro ou sem). Descreve o VOLUME de trabalho, não
 * classifica a pessoa.
 */
final readonly class MatrizDaPessoaOutput
{
    public function __construct(
        public string $chave,
        public string $estado,
        public string $tom,
    ) {
    }
}
