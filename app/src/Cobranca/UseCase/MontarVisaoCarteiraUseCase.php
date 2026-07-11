<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\CarteiraDetalheOutput;
use App\Cobranca\DTO\CasoResumoOutput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\ObjetoCobrancaRepository;
use App\Cobranca\Service\CalculadoraSaldo;

/**
 * Leitura: monta a visão da Carteira (Etapa 8) — cabeçalho de configuração + agregados (nº de
 * objetos e casos, saldo consolidado derivado) + a lista de casos da carteira com saldo por caso.
 * A carteira já vem resolvida por tenant no controller; aqui só agrega. Saldo é derivado
 * (invariável 20). Devolve Output DTOs.
 */
final class MontarVisaoCarteiraUseCase
{
    public function __construct(
        private readonly ObjetoCobrancaRepository $objetoRepository,
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly CalculadoraSaldo $calculadoraSaldo,
    ) {
    }

    /**
     * @return array{carteira: CarteiraDetalheOutput, casos: list<CasoResumoOutput>}
     */
    public function executar(Carteira $carteira): array
    {
        $casos = $this->casoRepository->daCarteira($carteira);

        // Saldos derivados em LOTE (uma carga tenant-scoped) — fim do N+1 de saldoExigivel+saldoVencido
        // por caso. Mesma regra dos métodos por-caso (via `CalculadoraSaldo::saldosDosCasos`).
        $saldos = $this->calculadoraSaldo->saldosDosCasos($casos, $carteira->getTenant());

        $saldoConsolidado = 0;
        $casosOutput = [];
        foreach ($casos as $caso) {
            $saldo = $saldos[$caso->getId() ?? 0] ?? ['exigivel' => 0, 'vencido' => 0];
            $saldoConsolidado += $saldo['exigivel'];
            $casosOutput[] = CasoResumoOutput::fromEntity($caso, $saldo['exigivel'], $saldo['vencido']);
        }

        return [
            'carteira' => CarteiraDetalheOutput::fromEntity(
                $carteira,
                $this->objetoRepository->contarDaCarteira($carteira),
                count($casos),
                $saldoConsolidado,
            ),
            'casos' => $casosOutput,
        ];
    }
}
