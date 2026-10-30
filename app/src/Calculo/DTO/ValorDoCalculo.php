<?php

declare(strict_types=1);

namespace App\Calculo\DTO;

/** Um valor devido ou um abatimento informado na tela: data AAAA-MM-DD, valor em pt-BR ("1.234,56") e descrição. */
final readonly class ValorDoCalculo
{
    public function __construct(
        public string $data,
        public string $valor,
        public string $descricao = '',
    ) {
    }

    /** @param array<string, mixed> $dados */
    public static function fromArray(array $dados): self
    {
        return new self(
            trim((string) ($dados['d'] ?? '')),
            trim((string) ($dados['v'] ?? '')),
            trim((string) ($dados['t'] ?? '')),
        );
    }

    /** "1.234,56" vira 1234.56; texto inválido vira 0. */
    public function valorNumerico(): float
    {
        $limpo = str_replace(['.', ','], ['', '.'], $this->valor);

        return is_numeric($limpo) ? (float) $limpo : 0.0;
    }

    /** Conta só quando tem data e valor maior que zero (mesma regra da tela). */
    public function completo(): bool
    {
        return $this->data !== '' && $this->valorNumerico() > 0;
    }
}
