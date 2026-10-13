<?php

declare(strict_types=1);

namespace App\Dashboard\DTO;

/**
 * A janela "Histórico" de um card do Dashboard, pronta para a tela (DASH-017).
 *
 * O servidor entrega o modelo de exibição inteiro — barras (altura, destaque, valor visível) e os
 * 3 indicadores já formatados — para o JS só desenhar. Toda conta (média, melhor mês, comparação
 * com o ano anterior) mora no UseCase, onde tem teste.
 *
 * `disponivel = false`: o card não tem série reconstruível; `opcoes` vem vazia e `nota` diz o
 * porquê. Nenhum número é estimado.
 *
 * Formato de cada opção (um ano, ou "Todos"):
 *   { chave: '2026'|'todos', rotulo: '2026'|'Todos',
 *     barras: list<{ rotulo, titulo, valor: int|null, altura: int, melhor: bool, atual: bool, mostrarValor: bool }>,
 *     stats:  list<{ rotulo, valor, sub, tom: 'neutro'|'ouro'|'bom'|'ruim'|'igual' }> }
 */
final class HistoricoDoCardOutput
{
    /**
     * @param list<array<string, mixed>> $opcoes
     */
    public function __construct(
        public readonly string $card,
        public readonly string $titulo,
        public readonly string $subtitulo,
        public readonly string $icone,
        public readonly bool $disponivel,
        public readonly string $nota,
        public readonly ?string $inicial,
        public readonly array $opcoes,
    ) {
    }

    /** @return array<string, mixed> */
    public function paraArray(): array
    {
        return [
            'card'       => $this->card,
            'titulo'     => $this->titulo,
            'subtitulo'  => $this->subtitulo,
            'icone'      => $this->icone,
            'disponivel' => $this->disponivel,
            'nota'       => $this->nota,
            'inicial'    => $this->inicial,
            'opcoes'     => $this->opcoes,
        ];
    }
}
