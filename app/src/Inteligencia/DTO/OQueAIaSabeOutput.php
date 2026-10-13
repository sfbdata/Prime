<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

/**
 * A grade "O que a IA sabe desta pasta" do drawer (desenho, dc L861-864 e L5691). Quatro dos
 * cinco números do desenho, cada um o que o contexto dos agentes LEVA, não o que a pasta tem:
 * `valor` é o total limitado pelo teto da seção no
 * {@see \App\Inteligencia\Contexto\MontadorDeContextoDaPasta}, `total` é o que existe no banco e
 * `corte` é a frase curta que aparece quando o teto cortou (senão `null`).
 *
 * O teto é o de CADA seção. O orçamento total de caracteres do montador ainda pode cortar mais
 * numa pasta de textos longos; esse corte depende do conteúdo e é declarado ao modelo, não aqui.
 * Nem todo agente lê toda seção (o cartão de cada um diz o que ele lê).
 *
 * O 5º do desenho ("fontes" = soma + 2) é heurística do protótipo e fica de fora.
 */
final readonly class OQueAIaSabeOutput
{
    /**
     * @param list<array{chave: string, valor: int, total: int, rotulo: string, corte: ?string}> $itens
     */
    public function __construct(
        private array $itens,
    ) {
    }

    /**
     * Na ordem do desenho (documentos, tarefas, prazos, anotações).
     *
     * @return list<array{chave: string, valor: int, total: int, rotulo: string, corte: ?string}>
     */
    public function itens(): array
    {
        return $this->itens;
    }
}
