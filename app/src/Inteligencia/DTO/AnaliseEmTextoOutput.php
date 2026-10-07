<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

/**
 * Uma análise CONCLUÍDA em texto puro, para o "Baixar .txt" do cartão (desenho 02 - EXPEDIENTES
 * 1.2.3, dc L1001-1003 e L5742). `conteudo` é texto, nunca HTML: o controller o serve como
 * `text/plain` com `nosniff`, então nada do que o modelo escreveu é interpretado pelo navegador.
 */
final readonly class AnaliseEmTextoOutput
{
    public function __construct(
        public string $nomeDoArquivo,
        public string $conteudo,
    ) {
    }
}
