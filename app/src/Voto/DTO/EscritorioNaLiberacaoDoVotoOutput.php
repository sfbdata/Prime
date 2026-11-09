<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/** Uma linha da grade "Liberação por escritório" da aba "Voto" do Master. Só o nome do escritório: nada de CPF ou e-mail. */
final class EscritorioNaLiberacaoDoVotoOutput
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $nome,
        public readonly bool $liberado,
    ) {
    }

    /** @param array{tenant_id: int, nome: string, liberado: bool} $linha */
    public static function daLinha(array $linha): self
    {
        return new self(
            tenantId: $linha['tenant_id'],
            nome: $linha['nome'],
            liberado: $linha['liberado'],
        );
    }
}
