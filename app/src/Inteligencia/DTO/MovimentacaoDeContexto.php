<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

/**
 * Uma movimentação já preparada para o prompt (texto plano, mascarado, truncado). `chave` é
 * 'pub:{id}' (PublicacaoDjen) ou 'mov:{id}' (MovimentacaoProcesso) — é por ela que a análise
 * seguinte sabe o que já foi lido ([NOVA]).
 */
final readonly class MovimentacaoDeContexto
{
    public function __construct(
        public string $chave,
        public ?string $data,
        public string $tipo,
        public string $fonte,
        public string $texto,
    ) {
    }

    public function id(): int
    {
        return (int) substr($this->chave, 4);
    }

    public function ehPublicacao(): bool
    {
        return str_starts_with($this->chave, 'pub:');
    }

    /** Linha como entra no prompt (sem o marcador [NOVA], que é decisão do prompt). */
    public function linha(): string
    {
        return sprintf('%s · %s · %s: %s', $this->data ?? 'sem data', $this->tipo, $this->fonte, $this->texto);
    }
}
