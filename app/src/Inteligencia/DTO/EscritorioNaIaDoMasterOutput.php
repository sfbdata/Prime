<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

use App\Inteligencia\Enum\ModoDeCobrancaDaIa;

/**
 * Uma linha da tabela "Escritórios" da aba do Master. Só o nome do escritório: nada de CPF ou e-mail de ninguém.
 * O aceite só aparece com a IA habilitada pelo admin (é o aceite vigente; o de quem desligou depois é histórico).
 */
final class EscritorioNaIaDoMasterOutput
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $nome,
        public readonly bool $liberada,
        public readonly ?\DateTimeImmutable $aceiteEm,
        public readonly ModoDeCobrancaDaIa $modoDeCobranca,
        public readonly ?int $orcamentoMensalCentavos,
    ) {
    }

    /** @param array{tenant_id: int, nome: string, liberada: bool, habilitada: bool, aceite_em: ?string, modo: ?string, orcamento: ?int} $linha */
    public static function daLinha(array $linha): self
    {
        return new self(
            tenantId: $linha['tenant_id'],
            nome: $linha['nome'],
            liberada: $linha['liberada'],
            aceiteEm: $linha['habilitada'] && $linha['aceite_em'] !== null ? new \DateTimeImmutable($linha['aceite_em']) : null,
            modoDeCobranca: ModoDeCobrancaDaIa::tryFrom((string) $linha['modo']) ?? ModoDeCobrancaDaIa::Incluso,
            orcamentoMensalCentavos: $linha['orcamento'],
        );
    }
}
