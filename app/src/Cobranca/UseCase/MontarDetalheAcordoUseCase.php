<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\AcordoDetalheOutput;
use App\Cobranca\DTO\ObrigacaoSubstituidaResumoOutput;
use App\Cobranca\DTO\ParcelaAcordoResumoOutput;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Service\EncargosVivos;
use App\Cobranca\Service\ResolvedorConfigEncargos;
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
        private readonly EncargosVivos $encargosVivos,
        private readonly ResolvedorConfigEncargos $resolvedorConfig,
    ) {
    }

    public function executar(Acordo $acordo, Tenant $tenant): AcordoDetalheOutput
    {
        $caso = $acordo->getCaso();
        $casoId = (int) $caso?->getId();

        // Encargos AO VIVO (spec §3 D4): a PARCELA de um acordo VIGENTE é obrigação viva — cresce se
        // atrasar. Hidrata-as para HOJE (config resolvida do caso, 1×). Acordo não-vigente (rompido/
        // cancelado) tem parcelas históricas → não hidrata. As SUBSTITUÍDAS ficam de fora e NÃO são
        // hidratadas: a F3 (CriarAcordo) materializou o snapshot na data do acordo (sem congelar), e como
        // aqui só lemos `valorExigivel()` delas (sem hidratar), a tela mostra exatamente esse snapshot.
        if ($caso !== null && $acordo->getStatus()->ehVigente()) {
            $this->encargosVivos->hidratar(
                $this->resolvedorConfig->resolverDoCaso($caso),
                $acordo->getParcelas(),
            );
        }

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
        // 🔑 Achado 🔴1 da revisão de 17/08. `valorExigivel()` = original + juros + multa + correção. Numa
        // obrigação substituída por acordo SEM DATA esses três encargos são o RESÍDUO da última hidratação
        // ao vivo — de uma data arbitrária, porque a materialização não roda sem data. Somá-los aqui
        // produziria os três números de dinheiro desta tela ("Dívida substituída", "Desconto concedido" e
        // a coluna Valor de cada linha) a partir de lixo, com cara de conta fechada.
        //
        // É o mesmo defeito que a fatia removeu do importador, reaparecendo numa segunda tela — e é a
        // repetição literal do caso `null|date`: a mudança de estado achou um caminho que ninguém tinha
        // percorrido. Por linha, mostra-se o PRINCIPAL (dado real da contabilidade) com o traço no
        // encargo; no agregado, **não se soma**: vira não apurável.
        $algumSemEncargoCalculado = false;

        foreach ($acordo->getObrigacoesSubstituidas() as $substituida) {
            $naoCalculada = $substituida->encargosNaoCalculados();
            $algumSemEncargoCalculado = $algumSemEncargoCalculado || $naoCalculada;
            $valorSubstituidas += $naoCalculada ? $substituida->getValorOriginal() : $substituida->valorExigivel();
            // DTO enxuto de propósito: não lê o acordo da substituída (evita lazy-load por linha no re-acordo).
            $substituidas[] = new ObrigacaoSubstituidaResumoOutput(
                id: $substituida->getId() ?? 0,
                descricao: $substituida->getDescricao(),
                valor: $naoCalculada ? $substituida->getValorOriginal() : $substituida->valorExigivel(),
                vencimento: $substituida->getVencimentoOriginal(),
                encargosNaoCalculados: $naoCalculada,
            );
        }

        // Snapshot da negociação; acordo anterior ao Ajuste 7 (sem snapshot) deriva o total das parcelas.
        $total = $acordo->getValorTotalNegociado() ?? $somaParcelas;
        // Positivo = desconto concedido; negativo = juros acrescidos à dívida original.
        // NULL quando alguma substituída está sem encargo calculado: a Σ estaria incompleta (só principais),
        // e um "desconto" derivado dela seria número inventado. Decisão do dono, 18/08.
        $desconto = $algumSemEncargoCalculado ? null : $valorSubstituidas - $total;

        return new AcordoDetalheOutput(
            id: $acordo->getId() ?? 0,
            objetoId: (int) $caso?->getObjeto()?->getId(),
            dataAcordo: $acordo->getDataAcordo(),
            statusLabel: $acordo->getStatus()->label(),
            statusBadgeClass: $acordo->getStatus()->badgeClass(),
            vigente: $acordo->getStatus()->ehVigente(),
            ativo: $acordo->estaAtivo(),
            casoEncerrado: $caso?->estaEncerrado() ?? false,
            motivoRompimento: $acordo->getMotivoRompimento(),
            motivoCancelamento: $acordo->getMotivoCancelamento(),
            valorTotalNegociado: $total,
            valorEntrada: $acordo->getValorEntrada(),
            valorSubstituidas: $algumSemEncargoCalculado ? null : $valorSubstituidas,
            valorDesconto: $desconto,
            // Só faz sentido falar em juros havendo dívida original para comparar (defesa: sem
            // substituídas o desconto seria -total e a tela anunciaria juros de uma dívida inexistente).
            temJuros: $desconto !== null && $valorSubstituidas > 0 && $desconto < 0,
            totalAlocado: $totalAlocado,
            estaIncompleto: $acordo->estaIncompleto(),
            parcelasFaltantes: $acordo->parcelasFaltantes(),
            parcelas: $parcelas,
            substituidas: $substituidas,
        );
    }
}
