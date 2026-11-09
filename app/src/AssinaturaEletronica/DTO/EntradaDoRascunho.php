<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\DTO;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/** O "Salvar rascunho" inteiro: o JSON conferido e, se vieram, o PDF e a logo própria (contrato, seção 4). */
final readonly class EntradaDoRascunho
{
    public function __construct(
        public DadosDoRascunho $dados,
        public ?UploadedFile $arquivo = null,
        public ?UploadedFile $logo = null,
    ) {
    }
}
