<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\DTO;

use App\Shared\Armazenamento\ChaveDeArquivo;

/**
 * O PDF com selo pronto para o download (E3-4): o guardado do documento concluído (pela chave) ou o gerado na hora,
 * em pedaços, dos demais. Um dos dois, nunca os dois; o nome é o do desenho (`assinado_<nome>.pdf`).
 */
final readonly class PdfParaEntregar
{
    /** @param \Generator<int, string>|null $pedacos */
    private function __construct(
        public string $nome,
        public ?ChaveDeArquivo $chave,
        public ?\Generator $pedacos,
    ) {
    }

    public static function guardado(ChaveDeArquivo $chave, string $nome): self
    {
        return new self($nome, $chave, null);
    }

    /** @param \Generator<int, string> $pedacos */
    public static function naHora(\Generator $pedacos, string $nome): self
    {
        return new self($nome, null, $pedacos);
    }
}
