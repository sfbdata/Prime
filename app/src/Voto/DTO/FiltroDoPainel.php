<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/**
 * Os filtros da Gestão eleitoral (`.vFil` do desenho): busca livre, entidade (a chave do grupo) e situação
 * (`and` em andamento, `ras` rascunhos, `con` concluídas; vazio = todas).
 */
final readonly class FiltroDoPainel
{
    public const SITUACOES = ['', 'and', 'ras', 'con'];

    public function __construct(
        public string $busca = '',
        public string $entidade = '',
        public string $situacao = '',
    ) {
    }

    /** Valor de URL qualquer vira filtro válido: situação fora da lista é "todas". */
    public static function de(string $busca, string $entidade, string $situacao): self
    {
        return new self(
            mb_substr(trim($busca), 0, 120),
            mb_substr(trim($entidade), 0, 200),
            \in_array($situacao, self::SITUACOES, true) ? $situacao : '',
        );
    }

    public function ativo(): bool
    {
        return '' !== $this->busca || '' !== $this->entidade || '' !== $this->situacao;
    }
}
