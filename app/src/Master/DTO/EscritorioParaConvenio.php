<?php

declare(strict_types=1);

namespace App\Master\DTO;

/**
 * O que a sincronização dos vínculos precisa de um escritório (spec docs/specs/convenios.md, §5.1), lido em lote pelo
 * {@see \App\Master\Repository\ConvenioVinculoRepository::escritoriosParaConvenio}.
 *
 * `oabValida`: o criador do escritório tem ao menos uma inscrição OAB que não está divergente. `uf`: a sigla tirada do
 * texto do estado (`LocalizacaoDaPessoa::uf`), nula sem estado válido.
 */
final class EscritorioParaConvenio
{
    public function __construct(
        public readonly int $tenantId,
        public readonly ?string $uf,
        public readonly bool $emQuarentena,
        public readonly bool $oabValida,
    ) {
    }
}
