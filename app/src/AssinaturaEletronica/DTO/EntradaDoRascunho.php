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
        /** A gravação que o "Gerar links e enviar" faz antes do envio: não registra "Rascunho salvo" (decisão de 10/11). */
        public bool $doEnvio = false,
    ) {
    }
}
