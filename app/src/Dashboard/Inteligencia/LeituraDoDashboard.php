<?php

declare(strict_types=1);

namespace App\Dashboard\Inteligencia;

/**
 * Tudo que o painel BlueJus Intelligence mostra para um carregamento do Dashboard: a leitura
 * básica (ritmo), a ampliada (Modo avançado), quando foi gerada e sobre qual período.
 */
final readonly class LeituraDoDashboard
{
    private const MESES = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

    public function __construct(
        public LeituraDoRitmo $ritmo,
        public LeituraAvancada $avancado,
        public \DateTimeImmutable $geradaEm,
        public ?TempoDoPeriodo $tempo,
    ) {}

    /** Riscos da leitura básica = "pontos de atenção" do botão (dc L2705-2710). */
    public function pontosDeAtencao(): int
    {
        return count($this->ritmo->riscos);
    }

    /** O botão ganha o ponto âmbar quando há alerta E pelo menos um risco (dc L2705). */
    public function temAlerta(): bool
    {
        return $this->ritmo->alerta && $this->pontosDeAtencao() > 0;
    }

    /**
     * "1 fev a 29 fev" como o `dd()` do desenho (dc L2682); com o ano quando o período
     * não é do ano corrente, para não confundir ao olhar um período antigo.
     */
    public function periodoTexto(): string
    {
        if ($this->tempo === null) {
            return 'sem período definido';
        }

        $anoAtual = (int) $this->geradaEm->format('Y');
        $comAno   = (int) $this->tempo->inicio->format('Y') !== $anoAtual || (int) $this->tempo->fim->format('Y') !== $anoAtual;

        return sprintf('%s a %s', $this->dia($this->tempo->inicio, $comAno), $this->dia($this->tempo->fim, $comAno));
    }

    private function dia(\DateTimeImmutable $d, bool $comAno): string
    {
        $texto = (int) $d->format('j') . ' ' . self::MESES[(int) $d->format('n') - 1];

        return $comAno ? $texto . ' ' . $d->format('Y') : $texto;
    }
}
