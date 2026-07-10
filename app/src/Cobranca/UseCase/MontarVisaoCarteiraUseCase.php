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

        $saldoConsolidado = 0;
        $casosOutput = [];
        foreach ($casos as $caso) {
            $saldoExigivel = $this->calculadoraSaldo->saldoExigivel($caso);
            $saldoConsolidado += $saldoExigivel;
            $casosOutput[] = CasoResumoOutput::fromEntity(
                $caso,
                $saldoExigivel,
                $this->calculadoraSaldo->saldoVencido($caso),
            );
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
