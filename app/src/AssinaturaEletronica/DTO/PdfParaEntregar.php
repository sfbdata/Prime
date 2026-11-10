<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\DTO;

use App\Shared\Armazenamento\ChaveDeArquivo;
use Symfony\Component\Lock\LockInterface;

/**
 * O PDF com selo pronto para o download (E3-4): o guardado do documento concluído (pela chave) ou o gerado na hora,
 * em pedaços, dos demais. Um dos dois, nunca os dois; o nome é o do desenho (`assinado_<nome>.pdf`). O gerado na hora
 * leva a vaga de geração que ocupa, liberada por quem entrega quando o último pedaço sai (ou a geração falha).
 */
final readonly class PdfParaEntregar
{
    /** @param \Generator<int, string>|null $pedacos */
    private function __construct(
        public string $nome,
        public ?ChaveDeArquivo $chave,
        public ?\Generator $pedacos,
        public ?LockInterface $vaga,
    ) {
    }

    public static function guardado(ChaveDeArquivo $chave, string $nome): self
    {
        return new self($nome, $chave, null, null);
    }

    /** @param \Generator<int, string> $pedacos */
    public static function naHora(\Generator $pedacos, string $nome, LockInterface $vaga): self
    {
        return new self($nome, null, $pedacos, $vaga);
    }
}
