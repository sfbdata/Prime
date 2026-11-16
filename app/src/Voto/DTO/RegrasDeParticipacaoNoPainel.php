<?php

declare(strict_types=1);

namespace App\Voto\DTO;

use App\Voto\Enum\OrdemDasCandidaturas;
use App\Voto\Enum\SigiloDoVoto;

/**
 * O horário programado, os grupos aptos, a ordem das candidaturas e o sigilo de uma pauta como o gestor a vê (desenho
 * 18), mais a relação nominal do voto aberto quando o sigilo já permite mostrá-la.
 */
final readonly class RegrasDeParticipacaoNoPainel
{
    /**
     * @param list<string>                  $grupos  grupos aptos; vazio = todos
     * @param list<VotoNominalNaPauta>|null $nominal null na pauta secreta e enquanto o sigilo não permite mostrar
     */
    public function __construct(
        public ?\DateTimeImmutable $abreEm = null,
        public ?\DateTimeImmutable $encerraEm = null,
        public array $grupos = [],
        public OrdemDasCandidaturas $ordem = OrdemDasCandidaturas::Fixa,
        public SigiloDoVoto $sigilo = SigiloDoVoto::Secreto,
        public ?array $nominal = null,
    ) {
    }
}
