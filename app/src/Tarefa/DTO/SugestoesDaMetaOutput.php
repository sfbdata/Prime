<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

/**
 * As sugestões do bloco DESPACHO para quem abre a meta (desenho 04B de 22/10, L61 e L151-156; Fase 4).
 *
 * `frases` traz as 3 frases por natureza marcada: a chave '' é sem natureza, as outras são o valor de
 * `NaturezaDoDespacho`; a tela troca a lista quando a pessoa marca outra natureza. `natureza` é a preferida, que já
 * vem marcada; `prazo` (Y-m-d) é o prazo usual quando existe e não é o padrão de 5 dias úteis. `naEtapa` escolhe a
 * dica: "Aprendido com seus despachos nesta etapa" (com natureza preferida ou prazo usual) ou "Sugestões dos seus
 * despachos anteriores".
 *
 * @phpstan-type Frase array{texto: string, rotulo: string, vezes: int, natureza: ?string}
 */
final readonly class SugestoesDaMetaOutput
{
    /** @param array<string, list<Frase>> $frases */
    public function __construct(
        public array $frases,
        public ?string $natureza,
        public int $prazoUsual,
        public ?string $prazo,
        public bool $naEtapa,
    ) {
    }

    /** Chave desligada ou nada aprendido: nenhuma sugestão, o formulário fica como sempre. */
    public static function nenhuma(): self
    {
        return new self([], null, 0, null, false);
    }

    /** @return list<Frase> as da natureza que já vem marcada */
    public function frasesIniciais(): array
    {
        return $this->frases[$this->natureza ?? ''] ?? [];
    }

    public function temFrases(): bool
    {
        foreach ($this->frases as $lista) {
            if ($lista !== []) {
                return true;
            }
        }

        return false;
    }
}
