<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

/**
 * O resultado da conferência do espelho contra o sistema
 * (SPEC docs/specs/cobranca-espelho-da-contabilidade.md §5).
 *
 * INV-RC1: **os baldes SOMAM.** `total de grupos do relatório == confere + falta + principalDiferente
 * + ambiguidadeDeCaso`. Se não somar, o comando falha em vez de reportar parcial — um número
 * incompleto com cara de completo é pior do que nenhum número.
 */
final readonly class ResultadoConferencia
{
    /**
     * @param list<array{chave: string, unidade: string, nn: ?string, competencia: ?string, motivo: string}> $faltaNoSistema
     * @param list<array{chave: string, unidade: string, nn: ?string, competencia: ?string}>                 $sobraNoSistema
     * @param list<array{chave: string, unidade: string, relatorio: int, sistema: int, diferenca: int}>      $principalDiferente
     * @param list<string>                                                                                  $ambiguidadeDeCaso
     */
    public function __construct(
        public string $carteira,
        public ?\DateTimeImmutable $dadosAte,
        public int $gruposNoRelatorio,
        public int $obrigacoesNoSistema,
        public int $confere,
        public array $faltaNoSistema,
        public array $sobraNoSistema,
        public array $principalDiferente,
        public array $ambiguidadeDeCaso,
        public int $principalRelatorio,
        public int $principalSistema,
    ) {
    }

    /** INV-RC1 — a identidade que precisa fechar. */
    public function baldesFecham(): bool
    {
        return $this->gruposNoRelatorio === $this->confere
            + count($this->faltaNoSistema)
            + count($this->principalDiferente)
            + count($this->ambiguidadeDeCaso);
    }

    public function alinhado(): bool
    {
        return $this->faltaNoSistema === []
            && $this->sobraNoSistema === []
            && $this->principalDiferente === []
            && $this->ambiguidadeDeCaso === [];
    }

    /**
     * Quantas divergências de cada tipo — o resumo que vai para a tela.
     *
     * @return array<string, int>
     */
    public function resumo(): array
    {
        return [
            'confere' => $this->confere,
            'falta no sistema' => count($this->faltaNoSistema),
            'sobra no sistema' => count($this->sobraNoSistema),
            'principal diferente' => count($this->principalDiferente),
            'ambiguidade de caso' => count($this->ambiguidadeDeCaso),
        ];
    }
}
