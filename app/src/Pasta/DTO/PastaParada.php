<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Pasta\Service\AnalisadorDePastasParadas;

/**
 * Uma pasta parada há mais de 30 dias, pronta para a folha e para os avisos (desenho 02,
 * `paradasDados` L4735: `num, cli, resp, proc, parado, ult, pontos, sug, nivel, cor`).
 *
 * `dias` é a parada (dias desde a última movimentação, ou desde a abertura sem nenhuma); `ultima`
 * é a data da última movimentação (nula = "nenhuma"). `nivel` e `cor` vêm da faixa de dias.
 */
final readonly class PastaParada
{
    /**
     * @param list<string> $pontos    a pré-análise, na ordem do desenho
     * @param list<string> $sugestoes na ordem do desenho
     */
    public function __construct(
        public int $pastaId,
        public string $numero,
        public string $cliente,
        public string $responsavel,
        public ?int $responsavelId,
        public ?string $processoNumero,
        public ?string $processoTribunal,
        public int $dias,
        public ?\DateTimeImmutable $ultima,
        public array $pontos,
        public array $sugestoes,
        public string $nivel,
        public string $cor,
        public bool $semContrato,
    ) {
    }

    public function semProcesso(): bool
    {
        return $this->processoNumero === null;
    }

    /** Mais de 90 dias ("Crítico"). */
    public function critica(): bool
    {
        return $this->dias > AnalisadorDePastasParadas::DIAS_CRITICO;
    }

    /**
     * A primeira sugestão que não é a do financeiro: é a que vai no aviso ao responsável (spec,
     * "Avisos"), que nunca fala do financeiro da pasta.
     */
    public function primeiraSugestaoSemFinanceiro(): string
    {
        foreach ($this->sugestoes as $sugestao) {
            if ($sugestao !== AnalisadorDePastasParadas::SUGESTAO_FINANCEIRO) {
                return $sugestao;
            }
        }

        return '';
    }
}
