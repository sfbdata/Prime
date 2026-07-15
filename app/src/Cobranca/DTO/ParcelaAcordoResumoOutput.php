<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

/**
 * Uma parcela do Acordo na tela de detalhe (Ajuste 7, Fatia 3). `valor` é o exigível da obrigação
 * (original + encargos, SPEC §10) em CENTAVOS; `alocado` é o quanto de pagamento já foi alocado nela
 * — DERIVADO das alocações (invariável 20), nunca coluna. `quitada` = alocado cobre o valor.
 */
final class ParcelaAcordoResumoOutput
{
    public function __construct(
        public readonly int $id,
        public readonly string $descricao,
        public readonly int $valor,
        public readonly \DateTimeImmutable $vencimento,
        public readonly int $alocado,
        public readonly bool $quitada,
    ) {
    }
}
