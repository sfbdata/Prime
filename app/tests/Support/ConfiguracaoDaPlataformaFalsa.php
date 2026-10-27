<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Contract\ConfiguracaoDaPlataforma;

/** Leitor de configuração em memória para os testes unitários (sem banco). Chave não definida: texto vazio. */
final class ConfiguracaoDaPlataformaFalsa implements ConfiguracaoDaPlataforma
{
    /** @param array<string, string> $valores */
    public function __construct(private array $valores = [])
    {
    }

    public static function cadastro(string $politica): self
    {
        return new self(['cadastro' => $politica]);
    }

    public function definir(string $chave, string $valor): void
    {
        $this->valores[$chave] = $valor;
    }

    public function valor(string $chave): string
    {
        return $this->valores[$chave] ?? '';
    }
}
