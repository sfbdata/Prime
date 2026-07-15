<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\AcordoDetalheOutput;
use App\Cobranca\DTO\ObrigacaoSubstituidaResumoOutput;
use App\Cobranca\DTO\ParcelaAcordoResumoOutput;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Entity\Tenant\Tenant;

/**
 * Monta o detalhe de um Acordo para a tela `cobranca_acordo_show` (Ajuste 7, Fatia 3) — LEITURA pura.
 *
 * História: o gestor abre um acordo para conferir o que foi negociado — quanto ficou o total, se teve
 * entrada, se houve desconto ou juros sobre a dívida original, quais parcelas já foram pagas e quais
 * obrigações saíram do saldo. O acordo chega já resolvido tenant-safe pelo controller (anti-IDOR).
 *
 * Regras de derivação (spec §7, D5): total/entrada vêm do SNAPSHOT do acordo, com fallback derivado
 * (Σ parcelas) para acordos anteriores ao ajuste; desconto/juros = Σ substituídas − total, sempre
 * derivado. O "alocado" de cada parcela sai das alocações reais (invariável 20) — carregado em LOTE
 * numa query (`somasPorObrigacaoDosCasos`), nunca por parcela (sem N+1).
 */
final class MontarDetalheAcordoUseCase
{
    public function __construct(
        private readonly AlocacaoPagamentoRepository $alocacaoRepository,
    ) {
    }

    public function executar(Acordo $acordo, Tenant $tenant): AcordoDetalheOutput
    {
        $caso = $acordo->getCaso();
        $casoId = (int) $caso?->getId();

        // Uma query para todas as parcelas do caso: mapa obrigacaoId → total alocado.
        $alocadoPorObrigacao = $this->alocacaoRepository->somasPorObrigacaoDosCasos([$casoId], $tenant);

        $parcelas = [];
        $somaParcelas = 0;
        $totalAlocado = 0;

        foreach ($acordo->getParcelas() as $parcela) {
            $valor = $parcela->valorExigivel();
            $alocado = $alocadoPorObrigacao[(int) $parcela->getId()] ?? 0;
            $somaParcelas += $valor;
            $totalAlocado += $alocado;

            $parcelas[] = new ParcelaAcordoResumoOutput(
                id: $parcela->getId() ?? 0,
                descricao: $parcela->getDescricao(),
                valor: $valor,
                vencimento: $parcela->getVencimentoOriginal(),
                alocado: $alocado,
                quitada: $alocado >= $valor,
            );
        }

        $substituidas = [];
        $valorSubstituidas = 0;

        foreach ($acordo->getObrigacoesSubstituidas() as $substituida) {
            $valorSubstituidas += $substituida->valorExigivel();
            // DTO enxuto de propósito: não lê o acordo da substituída (evita lazy-load por linha no re-acordo).
            $substituidas[] = new ObrigacaoSubstituidaResumoOutput(
                id: $substituida->getId() ?? 0,
                descricao: $substituida->getDescricao(),
                valor: $substituida->valorExigivel(),
                vencimento: $substituida->getVencimentoOriginal(),
            );
        }

        // Snapshot da negociação; acordo anterior ao Ajuste 7 (sem snapshot) deriva o total das parcelas.
        $total = $acordo->getValorTotalNegociado() ?? $somaParcelas;
        // Positivo = desconto concedido; negativo = juros acrescidos à dívida original.
        $desconto = $valorSubstituidas - $total;

        return new AcordoDetalheOutput(
            id: $acordo->getId() ?? 0,
            objetoId: (int) $caso?->getObjeto()?->getId(),
            dataAcordo: $acordo->getDataAcordo(),
            statusLabel: $acordo->getStatus()->label(),
            statusBadgeClass: $acordo->getStatus()->badgeClass(),
            vigente: $acordo->getStatus()->ehVigente(),
            motivoRompimento: $acordo->getMotivoRompimento(),
            motivoCancelamento: $acordo->getMotivoCancelamento(),
            valorTotalNegociado: $total,
            valorEntrada: $acordo->getValorEntrada(),
            valorSubstituidas: $valorSubstituidas,
            valorDesconto: $desconto,
            // Só faz sentido falar em juros havendo dívida original para comparar (defesa: sem
            // substituídas o desconto seria -total e a tela anunciaria juros de uma dívida inexistente).
            temJuros: $valorSubstituidas > 0 && $desconto < 0,
            totalAlocado: $totalAlocado,
            parcelas: $parcelas,
            substituidas: $substituidas,
        );
    }
}
