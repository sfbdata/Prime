<?php

declare(strict_types=1);

namespace App\Djen\DTO;

/** O que o acervo precisa da publicação mais recente de um processo. */
final class UltimaPublicacaoDoProcesso
{
    public function __construct(
        public readonly int $id,
        public readonly ?\DateTimeImmutable $disponibilizacao,
        public readonly ?string $nomeOrgao,
        public readonly bool $lida,
        public readonly ?int $prazoDias,
        public readonly ?\DateTimeImmutable $prazoCumpridoEm,
    ) {
    }
}
