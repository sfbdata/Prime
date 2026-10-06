<?php

declare(strict_types=1);

namespace App\Dashboard\Inteligencia;

/**
 * Saída do MotorDeRitmo: os números-base do período, os indicadores por dia (quando há
 * período) e as frases do painel básico — o que está acontecendo, por quê, o que fazer,
 * riscos — mais a lista honesta do que o painel NÃO consegue dizer com os dados atuais.
 */
final readonly class LeituraDoRitmo
{
    /**
     * @param Leitura[] $porQue
     * @param Leitura[] $acoes   ordenadas por peso (a primeira é "o que fazer agora")
     * @param Leitura[] $riscos  ordenados por nível
     * @param string[]  $limites
     */
    public function __construct(
        public ?TempoDoPeriodo $tempo,
        // Somas das linhas visíveis da tabela Desempenho (a linha de Total)
        public int $concluidas,
        public int $ativas,
        public int $vencidas,
        public int $prazos,
        public int $novas,
        // Por dia — null sem período (não há dia a contar)
        public ?float $ritmoAtual,
        public ?float $novasPorDia,
        public ?float $entradaVsSaida,
        public EquipeDoRitmo $equipe,
        public ?Leitura $oQue,
        public array $porQue,
        public array $acoes,
        public array $riscos,
        public bool $alerta,
        public array $limites,
    ) {}

    public function oQueFazer(): ?Leitura
    {
        return $this->acoes[0] ?? null;
    }
}
