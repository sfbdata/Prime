<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * O resultado de destacar (ou tirar o destaque de) um comentário: a cor que ficou e, quando a regra "um por aba"
 * desfez o destaque de outro comentário, qual era e de quem (para o aviso do dc L4603).
 */
final class DestaqueDoComentarioOutput
{
    public function __construct(
        public readonly ?string $cor,
        public readonly ?int $desfeitoComentario = null,
        public readonly ?string $desfeitoAutor = null,
    ) {
    }

    /** @return array{cor: ?string, desfeito: array{comentario: int, autor: ?string}|null} */
    public function paraJson(): array
    {
        return [
            'cor' => $this->cor,
            'desfeito' => $this->desfeitoComentario === null ? null : ['comentario' => $this->desfeitoComentario, 'autor' => $this->desfeitoAutor],
        ];
    }
}
