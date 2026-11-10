<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * O gestor muda o tipo, a data ou a hora de uma batida da folha (E10, plano-e10-ponto §6). O `alvo` é o da linha da
 * folha ('b{id do registro}' ou 'a{id da inclusão}'); `cabecaEsperada` é o ajuste que a tela mostrou como a forma atual
 * da batida (nulo na batida nunca ajustada): se outro ajuste entrou nesse meio tempo, a gravação é recusada.
 */
final class AjustarBatidaInput
{
    public function __construct(
        public readonly Tenant $escritorio,
        public readonly User $pessoa,
        public readonly string $alvo,
        public readonly ?int $cabecaEsperada,
        public readonly string $tipo,
        public readonly \DateTimeImmutable $dataHora,
        public readonly string $motivo,
        public readonly User $autor,
    ) {
    }
}
