<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/** Uma linha válida da lista colada, já limpa e no tamanho das colunas. Fração com ponto ("0.025000") ou null. */
final readonly class LinhaDeUnidade
{
    public function __construct(
        public int $linha,
        public string $identificacao,
        public string $bloco,
        public ?string $situacao,
        public ?string $fracao,
        public ?string $responsavel,
        public ?string $email,
    ) {
    }

    /** Chave de duplicidade: bloco e unidade sem caixa. */
    public function chave(): string
    {
        return self::chaveDe($this->bloco, $this->identificacao);
    }

    public static function chaveDe(string $bloco, string $identificacao): string
    {
        return mb_strtolower(trim($bloco)) . '|' . mb_strtolower(trim($identificacao));
    }
}
