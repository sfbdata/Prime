<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

use App\Tarefa\Enum\EtapaDaPasta;

/**
 * O pedido de meta nova para a pasta, já fora do formato da tela (o modal da pasta) ou do conector de I.A.
 * Prazo e etapa chegam nulos quando o campo veio vazio ou fora do formato: quem recusa é o `CriarMetaUseCase`,
 * com a mesma mensagem nos dois caminhos. A análise do prazo só vem quando quem pediu a mostrou (desenho 02).
 */
final class CriarMetaInput
{
    /**
     * @param list<int> $responsaveis ids das pessoas; só colega ativo do escritório é aceito
     */
    public function __construct(
        public readonly string $titulo,
        public readonly string $descricao,
        public readonly ?\DateTimeImmutable $prazo,
        public readonly array $responsaveis,
        public readonly ?EtapaDaPasta $etapa,
        public readonly ?AnalisarPrazoDaMetaInput $analise = null,
    ) {
    }
}
