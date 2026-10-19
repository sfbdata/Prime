<?php

declare(strict_types=1);

namespace App\Central\DTO;

/**
 * Uma pessoa on-line no escritório, para o selo e o painel de presença do cabeçalho (spec
 * `presenca-e-ponto-no-cabecalho.md` §2). Só id, status e um número: o nome e o cargo vêm de `central_equipe`.
 */
final class PresencaOnlineOutput
{
    public function __construct(
        public readonly int $id,
        /** `disponivel` | `ocupado` | `ausente` | `inativo`. */
        public readonly string $status,
        /** Só para `inativo`: há quantos minutos a pessoa não mexe em nada. */
        public readonly ?int $semAtividadeMin,
    ) {
    }
}
