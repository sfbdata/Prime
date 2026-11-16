<?php

declare(strict_types=1);

namespace App\Voto\DTO;

use App\Voto\Enum\BaseDaDecisao;
use App\Voto\Enum\OrdemDasCandidaturas;
use App\Voto\Enum\RegraDeDecisao;
use App\Voto\Enum\SigiloDoVoto;

/**
 * As regras de uma pauta como a tela envia (desenho 18): critério, base de cálculo, dígitos dos números, vagas e quórum
 * da 1ª e da 2ª convocação (nulo repete o da 1ª), horário programado (abre e encerra sozinha; os dois nulos = conduzida pela
 * mesa), grupos aptos (vazio = todos), ordem das candidaturas e sigilo do voto. Os limites são conferidos pela pauta.
 */
final readonly class RegrasDaPautaInput
{
    /** @param list<string> $grupos */
    public function __construct(
        public RegraDeDecisao $criterio = RegraDeDecisao::Mais,
        public BaseDaDecisao $base = BaseDaDecisao::Validos,
        public int $digitos = 1,
        public int $vagas = 1,
        public int $quorumPrimeira = 50,
        public ?int $quorumSegunda = 0,
        public ?\DateTimeImmutable $abreEm = null,
        public ?\DateTimeImmutable $encerraEm = null,
        public array $grupos = [],
        public OrdemDasCandidaturas $ordem = OrdemDasCandidaturas::Fixa,
        public SigiloDoVoto $sigilo = SigiloDoVoto::Secreto,
    ) {
    }
}
