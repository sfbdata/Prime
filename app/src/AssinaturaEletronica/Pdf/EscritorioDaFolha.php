<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf;

/** O que o cabeçalho imprime do escritório (dc:410): nome em negrito e "endereço · telefone" abaixo. */
final readonly class EscritorioDaFolha
{
    public function __construct(
        public string $nome,
        public string $endereco = '',
        public string $telefone = '',
    ) {
    }
}
