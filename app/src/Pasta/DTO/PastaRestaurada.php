<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * Resultado de restaurar uma pasta da lixeira: o número que ela tinha ao ser excluída e o que
 * tem agora (diferente quando outra pasta viva já usava o número antigo).
 */
final readonly class PastaRestaurada
{
    public function __construct(
        public string $numeroAntes,
        public string $numeroAgora,
    ) {}

    public function mudouDeNumero(): bool
    {
        return $this->numeroAntes !== $this->numeroAgora;
    }
}
