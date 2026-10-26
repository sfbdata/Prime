<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto;

use App\Shared\Armazenamento\CategoriaDeArquivo;

/**
 * Quais categorias espelham no R2 — **nenhuma, por padrão** (spec P2 §9, §14).
 *
 * Vem de `ARMAZENAMENTO_ESPELHO_CATEGORIAS`, uma lista separada por vírgula de valores de
 * {@see CategoriaDeArquivo} (`foto_perfil,cliente_documento`). Vazia = desligado. É a chave da
 * implantação progressiva (P2.5): liga uma categoria por vez, e esvaziar a variável desliga na hora.
 *
 * Entrada que não é categoria **não liga nada**: fica em {@see entradasInvalidas()} para quem sobe a
 * aplicação ver o erro de digitação, mas não derruba o container nem espelha o que não foi pedido.
 */
final class PoliticaDeEspelhamento
{
    /** @var array<string, true> */
    private array $ligadas = [];

    /** @var list<string> */
    private array $invalidas = [];

    /** @var list<int> */
    private array $posicoesInvalidas = [];

    public function __construct(string $categorias = '')
    {
        foreach (explode(',', $categorias) as $indice => $entrada) {
            $entrada = trim($entrada);
            if ($entrada === '') {
                continue;
            }

            $categoria = CategoriaDeArquivo::tryFrom($entrada);
            if ($categoria === null) {
                $this->invalidas[]         = $entrada;
                $this->posicoesInvalidas[] = $indice + 1;

                continue;
            }

            $this->ligadas[$categoria->value] = true;
        }
    }

    public static function desligada(): self
    {
        return new self('');
    }

    public function espelha(CategoriaDeArquivo $categoria): bool
    {
        return isset($this->ligadas[$categoria->value]);
    }

    public function estaLigada(): bool
    {
        return $this->ligadas !== [];
    }

    /** @return list<string> */
    public function categoriasLigadas(): array
    {
        return array_keys($this->ligadas);
    }

    /**
     * Onde estão as entradas inválidas na lista separada por vírgula (1 = a primeira). É o que a tela de
     * situação mostra: o texto da entrada pode ser qualquer coisa colada por engano, até um segredo.
     *
     * @return list<int>
     */
    public function posicoesInvalidas(): array
    {
        return $this->posicoesInvalidas;
    }

    /** @return list<string> */
    public function entradasInvalidas(): array
    {
        return $this->invalidas;
    }
}
