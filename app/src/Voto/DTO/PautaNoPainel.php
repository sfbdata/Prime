<?php

declare(strict_types=1);

namespace App\Voto\DTO;

use App\Voto\Enum\BaseDaDecisao;
use App\Voto\Enum\EstadoDaPauta;
use App\Voto\Enum\RegraDeDecisao;

/**
 * Uma pauta como o gestor a vê (painel, mesa, acompanhar, resultados e ata). Participação só em números (quem votou
 * e o peso delas), nunca parcial de votos: o `resultado` só vem preenchido com a pauta encerrada.
 */
final readonly class PautaNoPainel
{
    /** Cor do chip por situação (desenho: Rascunho, Publicada, Votação aberta, Encerrada). */
    public const CORES = ['rascunho' => '#5b7383', 'publicada' => '#0a6fa0', 'aberta' => '#1e7b45', 'encerrada' => '#8a6200'];

    /**
     * @param list<array{numero: int, rotulo: string}> $opcoes só as opções de voto, sem a abstenção
     * @param string                                   $pesoVotante soma do peso de quem votou, 6 casas
     * @param string                                   $pesoApto    soma do peso apto da assembleia, 6 casas
     */
    public function __construct(
        public int $id,
        public int $ordem,
        public string $titulo,
        public ?string $descricao,
        public EstadoDaPauta $estado,
        public bool $assembleiaPublicada,
        public ?int $convocacao,
        public ?string $quorumExigido,
        public ?\DateTimeImmutable $abertaEm,
        public ?string $abertaPorNome,
        public ?\DateTimeImmutable $prazoAte,
        public ?\DateTimeImmutable $encerradaEm,
        public ?string $encerradaPorNome,
        public ?string $motivoEncerramento,
        public array $opcoes,
        public int $votantes,
        public string $pesoVotante,
        public int $unidadesAptas,
        public string $pesoApto,
        public ?ResultadoDaPauta $resultado,
        public RegraDeDecisao $criterio = RegraDeDecisao::Mais,
        public BaseDaDecisao $base = BaseDaDecisao::Validos,
    ) {
    }

    /** rascunho | publicada | aberta | encerrada (chave do chip e do texto). */
    public function situacao(): string
    {
        if (!$this->assembleiaPublicada) {
            return 'rascunho';
        }

        return match ($this->estado) {
            EstadoDaPauta::Aguardando => 'publicada',
            EstadoDaPauta::Aberta => 'aberta',
            EstadoDaPauta::Encerrada => 'encerrada',
        };
    }

    public function cor(): string
    {
        return self::CORES[$this->situacao()];
    }

    public function aberta(): bool
    {
        return EstadoDaPauta::Aberta === $this->estado;
    }

    public function encerrada(): bool
    {
        return EstadoDaPauta::Encerrada === $this->estado;
    }

    public function aguardandoAMesa(): bool
    {
        return $this->assembleiaPublicada && EstadoDaPauta::Aguardando === $this->estado;
    }

    /** Barra do painel: quem votou sobre as unidades aptas, inteiro de 0 a 100 (como o desenho). */
    public function pctUnidades(): int
    {
        if ($this->unidadesAptas <= 0) {
            return 0;
        }

        return min(100, (int) round($this->votantes * 100 / $this->unidadesAptas));
    }

    /** Peso de quem votou sobre o peso apto, com 2 casas ("52.30"); 0 sem peso apto. */
    public function pctPeso(): string
    {
        if (bccomp($this->pesoApto, '0', 6) <= 0) {
            return '0.00';
        }

        return bcadd(bcdiv(bcmul($this->pesoVotante, '100', 12), $this->pesoApto, 12), '0.005', 2);
    }

    /** O percentual para a tela, com no máximo 1 casa e vírgula ("52,3", "50"). */
    public function pctPesoTexto(): string
    {
        $texto = number_format((float) $this->pctPeso(), 1, ',', '.');

        return str_ends_with($texto, ',0') ? substr($texto, 0, -2) : $texto;
    }

    /** Largura da barra do Assistir, de 0 a 100. */
    public function larguraDaBarra(): string
    {
        return bccomp($this->pctPeso(), '100', 2) > 0 ? '100' : $this->pctPeso();
    }

    /** Quórum exigido sem casas zeradas ("50", "0"); vazio enquanto a pauta não foi aberta. */
    public function quorumTexto(): string
    {
        return null === $this->quorumExigido ? '' : self::semZeros($this->quorumExigido);
    }

    public function exigeQuorum(): bool
    {
        return null !== $this->quorumExigido && bccomp($this->quorumExigido, '0', 2) > 0;
    }

    /** O peso de quem votou alcança o quórum exigido (mesma conta da apuração). */
    public function quorumAtingido(): bool
    {
        if (null === $this->quorumExigido) {
            return false;
        }
        if (bccomp($this->pesoApto, '0', 6) <= 0) {
            return bccomp($this->quorumExigido, '0', 2) <= 0;
        }

        return bccomp(bcmul($this->pesoVotante, '100', 12), bcmul($this->quorumExigido, $this->pesoApto, 12), 12) >= 0;
    }

    /** Segundos até o prazo (0 se já passou); null sem prazo ou fora da votação aberta. */
    public function segundosRestantes(\DateTimeImmutable $agora): ?int
    {
        if (!$this->aberta() || null === $this->prazoAte) {
            return null;
        }

        return max(0, $this->prazoAte->getTimestamp() - $agora->getTimestamp());
    }

    private static function semZeros(string $decimal): string
    {
        return str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal;
    }
}
