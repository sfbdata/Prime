<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

/**
 * Uma linha do bloco de pessoas no trilho: quem delegou para você (aba do responsável) ou
 * com quem estão suas metas (aba Criei).
 */
final class PessoaNoTrilhoOutput
{
    public function __construct(
        public readonly int $id,
        public readonly string $nome,
        public readonly int $abertas,
        public readonly int $atrasadas,
    ) {
    }

    /** Iniciais para o avatar: primeiro e último nome, no máximo duas letras. */
    public function iniciais(): string
    {
        $partes = preg_split('/\s+/', trim($this->nome)) ?: [];
        $partes = array_values(array_filter($partes, static fn (string $p): bool => $p !== ''));

        if ($partes === []) {
            return '?';
        }

        $primeira = mb_strtoupper(mb_substr($partes[0], 0, 1));
        if (count($partes) === 1) {
            return $primeira;
        }

        return $primeira . mb_strtoupper(mb_substr($partes[count($partes) - 1], 0, 1));
    }
}
