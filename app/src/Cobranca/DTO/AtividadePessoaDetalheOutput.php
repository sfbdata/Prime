<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

/**
 * Detalhe de uma pessoa na aba Atividade, carregado SOB DEMANDA (rota própria) — a tabela não embute o
 * detalhe de todo mundo, senão a primeira consulta cresce com o tamanho da equipe (spec §8).
 *
 * `truncado` existe para a tela nunca mentir por omissão: a lista para no limite e avisa.
 */
final class AtividadePessoaDetalheOutput
{
    /**
     * @param list<DesfechoContatoOutput> $desfechos
     * @param list<EventoAtividadeOutput> $eventos
     */
    public function __construct(
        public readonly ?int $usuarioId,
        public readonly string $nome,
        public readonly array $desfechos,
        public readonly array $eventos,
        public readonly bool $truncado,
        public readonly int $limite,
    ) {
    }

    public function semResponsavel(): bool
    {
        return $this->usuarioId === null;
    }
}
