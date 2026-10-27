<?php

declare(strict_types=1);

namespace App\Dashboard\Inteligencia;

/**
 * "Simular dia" do painel BlueJus Intelligence (desenho 01, rodapé: "Teste · simular dia").
 *
 * Parâmetro de LEITURA da tela: só troca o "hoje" que alimenta o cálculo da Inteligência (dias do
 * período, fase e ritmo da meta). Não grava nada e não muda o "hoje" do resto do sistema. O dia
 * vem da querystring (`dia_simulado`, 'Y-m-d') e só vale dentro do período filtrado e até o último
 * dia do mês corrente (nunca futuro além do fim do mês). Valor inválido, fora da faixa ou igual ao
 * dia real não simula: o painel cai no hoje de verdade.
 */
final readonly class SimulacaoDeDia
{
    private function __construct(
        /** O dia simulado; nulo = sem simulação (hoje de verdade). */
        public ?\DateTimeImmutable $dia,
        /** Posição do dia no período (1..maximo): o valor do controle. */
        public int $numero,
        /** Posição de hoje no período (1..maximo): a que o controle volta ao desfazer. */
        public int $diaReal,
        /** Último dia que o controle alcança. */
        public int $maximo,
        /** Primeiro dia do período, 'Y-m-d': âncora para o controle montar a data. */
        public string $inicio,
    ) {
    }

    /** O controle só existe com período completo (sem ele não há dias a percorrer). */
    public static function de(?string $valor, ?TempoDoPeriodo $tempoReal): ?self
    {
        if ($tempoReal === null) {
            return null;
        }

        $diaReal = max(1, $tempoReal->passados);
        $maximo  = self::maximo($tempoReal);
        $dia     = self::ler($valor, $tempoReal, $maximo);

        return new self(
            $dia,
            $dia === null ? $diaReal : self::posicao($tempoReal, $dia),
            min($diaReal, $maximo),
            $maximo,
            $tempoReal->inicio->format('Y-m-d'),
        );
    }

    public function ativa(): bool
    {
        return $this->dia !== null;
    }

    /** O "hoje" da leitura: o dia simulado ou o real. */
    public function hoje(\DateTimeImmutable $agora): \DateTimeImmutable
    {
        return $this->dia ?? $agora;
    }

    private static function maximo(TempoDoPeriodo $tempo): int
    {
        // O fim do mês corrente (o de `hoje`, sem hora): a simulação nunca passa dele.
        $limite = min($tempo->fim, $tempo->hoje->modify('last day of this month'));

        return max(1, min($tempo->total, self::posicao($tempo, $limite)));
    }

    private static function ler(?string $valor, TempoDoPeriodo $tempo, int $maximo): ?\DateTimeImmutable
    {
        $valor = trim((string) $valor);
        $dia   = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor, new \DateTimeZone('UTC'));
        if ($dia === false || $dia->format('Y-m-d') !== $valor) {
            return null;
        }

        $posicao = self::posicao($tempo, $dia);
        if ($posicao < 1 || $posicao > $maximo || $posicao === max(1, $tempo->passados)) {
            return null;
        }

        return $dia;
    }

    private static function posicao(TempoDoPeriodo $tempo, \DateTimeImmutable $dia): int
    {
        $dias  = (int) $tempo->inicio->diff($dia)->days;
        $sinal = $dia < $tempo->inicio ? -1 : 1;

        return $sinal * $dias + 1;
    }
}
