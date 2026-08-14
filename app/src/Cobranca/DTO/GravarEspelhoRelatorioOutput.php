<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

/**
 * Saída de {@see \App\Cobranca\UseCase\GravarEspelhoRelatorioUseCase}.
 *
 * `$jaExistia` distingue os dois desfechos felizes: gravou agora, ou já estava lá e nada foi
 * escrito. A carga do histórico é reexecutável justamente por isso — rodar de novo é barato e não
 * duplica.
 */
final readonly class GravarEspelhoRelatorioOutput
{
    /**
     * @param array<string, int>      $linhasPorBloco  contagem por balde; a soma == $linhasTotal
     * @param array<string, int>|null $configDeclarada taxas em basis points, como a contabilidade declarou
     * @param array{abas: int, centavos: int}|null $toleranciaDeRateio
     *        Só nos ACORDOS: quantas abas fecharam apenas dentro da tolerância de rateio, e quantos
     *        centavos isso consumiu. ⚠️ **Tolerância silenciosa é descarte silencioso com outro
     *        nome** — este campo existe para o número chegar à tela, não para ficar no log.
     */
    public function __construct(
        public int $relatorioId,
        public bool $jaExistia,
        public string $arquivoNome,
        public string $arquivoHash,
        public ?\DateTimeImmutable $emitidoEm,
        public ?\DateTimeImmutable $dadosAte,
        public ?array $configDeclarada,
        public int $linhasTotal,
        public int $linhasDados,
        public int $linhasTotalizador,
        public array $linhasPorBloco,
        public ?array $toleranciaDeRateio = null,
    ) {
    }
}
