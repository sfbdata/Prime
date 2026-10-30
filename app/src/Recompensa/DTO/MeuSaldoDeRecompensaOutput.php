<?php

declare(strict_types=1);

namespace App\Recompensa\DTO;

/**
 * O cartão "Seu saldo de recompensa" do Contribua (desenho 08 VS 30.10.26, seção `indique`): o saldo de quem está
 * logado, calculado pelas regras do painel de Recompensas do Master, o cupom e os três contadores. Dinheiro em
 * centavos; os textos prontos saem dos métodos, com as regras do script do desenho.
 */
final readonly class MeuSaldoDeRecompensaOutput
{
    public function __construct(
        public int $saldoCentavos,
        public int $pagoCentavos,
        /** Quanto vale cada escritório ativo (regra geral ou condição individual). */
        public int $valorCentavos,
        public int $minEscritorios,
        public int $ativos,
        public bool $liberado,
        public bool $exigePasta,
        public string $cupom,
        public int $convites,
        public int $contasAbertas,
    ) {
    }

    public function saldo(): string
    {
        return self::reais($this->saldoCentavos);
    }

    /** "10" ou "10,50": o desenho tira o ",00" do prêmio. */
    public function premio(): string
    {
        return str_replace(',00', '', number_format($this->valorCentavos / 100, 2, ',', '.'));
    }

    public function situacao(): string
    {
        if ($this->liberado) {
            return 'Liberado · ' . $this->ativos . ' escritório(s) ativo(s)' . ($this->pagoCentavos > 0 ? ' · já recebeu ' . self::reais($this->pagoCentavos) : '');
        }

        return 'Libera com ' . $this->minEscritorios . ' escritórios ativos' . ($this->exigePasta ? ' com pastas' : '') . ' · você tem ' . $this->ativos;
    }

    /** Convites ainda sem conta aberta. */
    public function aguardando(): int
    {
        return max(0, $this->convites - $this->contasAbertas);
    }

    private static function reais(int $centavos): string
    {
        return 'R$ ' . number_format($centavos / 100, 2, ',', '.');
    }
}
