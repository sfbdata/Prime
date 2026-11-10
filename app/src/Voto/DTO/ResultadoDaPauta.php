<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/**
 * Resultado de uma pauta (ApuracaoDaPauta; regra do desenho, docs/specs/voto-assembleia-desenho.md §5): critério
 * "Mais votos" sobre os votos válidos. Pesos em string com 6 casas; percentuais em string com 2 casas. Nunca float.
 */
final readonly class ResultadoDaPauta
{
    public const DECIDIDO = 'Decidido';
    public const QUORUM_NAO_ATINGIDO = 'Quórum não atingido';
    public const EMPATE = 'Empate';
    public const CRITERIO_NAO_ATINGIDO = 'Critério não atingido';

    /**
     * @param list<ResultadoDaOpcao> $opcoes       só as opções de voto, por peso decrescente e, no empate de peso, pelo número
     * @param string                 $situacao     uma das constantes desta classe (texto literal do desenho)
     * @param string                 $pesoValido   soma do peso das opções de voto
     * @param string                 $pesoPresente peso válido + abstenções
     * @param string                 $quorumPct    peso presente × 100 ÷ peso apto (0 sem peso apto)
     */
    public function __construct(
        public string $situacao,
        public ?ResultadoDaOpcao $vencedor,
        public array $opcoes,
        public bool $empate,
        public int $abstencoesUnidades,
        public string $abstencoesPeso,
        public int $votantes,
        public string $pesoValido,
        public string $pesoPresente,
        public string $pesoApto,
        public int $unidadesAptas,
        public string $quorumPct,
        public string $quorumExigido,
        public bool $quorumOk,
    ) {
    }

    public function decidido(): bool
    {
        return null !== $this->vencedor;
    }
}
