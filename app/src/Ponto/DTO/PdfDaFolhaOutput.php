<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

/**
 * A folha do mês em PDF pronta para a resposta (E16): os bytes do PDF, o nome do arquivo do desenho
 * (`folha_ponto_<NOME>-MM-AAAA.pdf`, L726) e o código BJF da emissão, ou null na folha sem código (sem o segredo do
 * HMAC, decisão 16).
 */
final readonly class PdfDaFolhaOutput
{
    public function __construct(
        public string $pdf,
        public string $nomeDoArquivo,
        public ?string $codigo,
    ) {
    }
}
