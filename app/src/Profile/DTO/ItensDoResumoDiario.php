<?php

declare(strict_types=1);

namespace App\Profile\DTO;

/**
 * O que vai no "Resumo diário por e-mail" de uma pessoa num escritório (spec docs/specs/resumo-diario-por-email.md):
 * prazos e audiências do dia, nas pastas de que ela é responsável e na agenda dela, e as metas que vencem hoje.
 */
final class ItensDoResumoDiario
{
    /**
     * @param list<array{titulo: string, tipo: string, hora: ?string, pasta: ?string}> $prazos
     * @param list<array{titulo: string, pasta: ?string}>                            $metas
     */
    public function __construct(
        public readonly array $prazos,
        public readonly array $metas,
    ) {
    }

    public function vazio(): bool
    {
        return $this->prazos === [] && $this->metas === [];
    }
}
