<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\AnaliseSemProcessoDaPasta;
use App\Pasta\DTO\RelatorioSemProcessoOutput;
use App\Pasta\DTO\ResumoSemProcesso;
use App\Pasta\Entity\EnvioDoRelatorioSemProcesso;
use App\Pasta\Repository\DecisaoSemProcessoDaPastaRepository;
use App\Pasta\Repository\EnvioDoRelatorioSemProcessoRepository;

/**
 * Monta o relatório de pastas sem processo de um escritório: coleta, analisa, ordena por número e
 * junta o histórico. Uma peça só para a tela, o PDF e o envio à Controladoria, para os três
 * contarem a mesma coisa.
 */
final class MontagemDoRelatorioSemProcesso
{
    /** A Evolução mostra os últimos 6 envios + Hoje (L4547). */
    private const MESES_NA_EVOLUCAO = 6;

    public function __construct(
        private readonly ColetorDePastasSemProcesso $coletor,
        private readonly AnalisadorDePastasSemProcesso $analisador,
        private readonly DecisaoSemProcessoDaPastaRepository $decisaoRepository,
        private readonly EnvioDoRelatorioSemProcessoRepository $envioRepository,
    ) {
    }

    /**
     * @param list<int>|null $ids nulo = todas as pastas do escritório
     */
    public function montar(Tenant $tenant, ?array $ids, bool $comHistorico, \DateTimeImmutable $hoje): RelatorioSemProcessoOutput
    {
        $decisoes = $this->decisaoRepository->doEscritorio($tenant);
        $analises = $this->analisador->analisar($this->coletor->coletar($tenant, $ids), $decisoes, $hoje);
        usort($analises, static fn (AnaliseSemProcessoDaPasta $a, AnaliseSemProcessoDaPasta $b): int => strnatcmp($a->numero, $b->numero) ?: $a->pastaId <=> $b->pastaId);

        $historico = $comHistorico
            ? array_map(
                static fn (EnvioDoRelatorioSemProcesso $e): array => ['mes' => $e->getMes(), 'resumo' => $e->resumo()],
                $this->envioRepository->ultimos($tenant, self::MESES_NA_EVOLUCAO),
            )
            : [];

        return new RelatorioSemProcessoOutput($hoje, $analises, ResumoSemProcesso::deAnalises($analises), $historico, \count($decisoes));
    }
}
