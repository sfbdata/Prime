<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

/**
 * Uma obrigação substituída pelo Acordo, na tela de detalhe (Ajuste 7, Fatia 3). `valor` é o exigível
 * (original + encargos) em CENTAVOS.
 *
 * Enxuto de propósito: NÃO expõe o estado do acordo da obrigação (ao contrário do `ObrigacaoOutput`,
 * que lê `acordoOrigem`/`acordoSubstituto`). Uma substituída pode ela mesma ser parcela de um acordo
 * anterior (re-acordo — `doCasoExigiveis` deixa parcela de acordo vigente ser substituída), e ler o
 * acordo dela dispararia lazy-load por linha (N+1). Aqui a tela só precisa descrever o que saiu do saldo.
 */
final class ObrigacaoSubstituidaResumoOutput
{
    public function __construct(
        public readonly int $id,
        public readonly string $descricao,
        public readonly int $valor,
        public readonly \DateTimeImmutable $vencimento,
    ) {
    }
}
