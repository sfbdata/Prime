<?php

declare(strict_types=1);

namespace App\Voto\DTO;

use App\Voto\Enum\BaseDaDecisao;
use App\Voto\Enum\RegraDeDecisao;

/**
 * Resultado de uma pauta (ApuracaoDaPauta; regra do desenho, docs/specs/voto-assembleia-desenho.md §5): critério e base
 * da pauta, com uma ou várias vagas. `vencedores` é a lista de eleitos (um só na vaga única); `vencedor` é o primeiro
 * deles. `votantes` conta as unidades que participaram, cada uma uma vez (numa pauta com várias vagas a soma dos
 * contadores das opções passa disso). Pesos em string com 6 casas; percentuais em string com 2 casas. Nunca float.
 */
final readonly class ResultadoDaPauta
{
    public const DECIDIDO = 'Decidido';
    public const QUORUM_NAO_ATINGIDO = 'Quórum não atingido';
    public const EMPATE = 'Empate';
    public const CRITERIO_NAO_ATINGIDO = 'Critério não atingido';
    public const EMPATE_NA_ULTIMA_VAGA = 'Empate na última vaga';

    /**
     * @param list<ResultadoDaOpcao> $opcoes       só as opções de voto, por peso decrescente e, no empate de peso, pelo número
     * @param string                 $situacao     uma das constantes desta classe (texto literal do desenho)
     * @param string                 $pesoValido   soma do peso das opções de voto
     * @param string                 $pesoPresente peso válido + abstenções
     * @param string                 $quorumPct    peso presente × 100 ÷ peso apto (0 sem peso apto)
     * @param list<ResultadoDaOpcao> $vencedores   os eleitos, pelo peso; vazio quando não decidida
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
        public array $vencedores = [],
        public RegraDeDecisao $criterio = RegraDeDecisao::Mais,
        public BaseDaDecisao $base = BaseDaDecisao::Validos,
        public int $vagas = 1,
    ) {
    }

    /** Total da tabela de apuração: escolhas em candidatos e abstenções, não participantes. */
    public function totalDeEscolhas(): int
    {
        return array_sum(array_map(static fn (ResultadoDaOpcao $opcao): int => $opcao->unidades, $this->opcoes)) + $this->abstencoesUnidades;
    }

    public function pesoDasEscolhas(): string
    {
        return bcadd($this->pesoValido, $this->abstencoesPeso, 6);
    }

    public function decidido(): bool
    {
        return [] !== $this->vencedores || null !== $this->vencedor;
    }
}
