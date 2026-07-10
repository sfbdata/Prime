<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\DashboardCobrancaOutput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Enum\TipoAlerta;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\LiquidacaoRepository;
use App\Cobranca\Repository\PagamentoRepository;
use App\Cobranca\Service\AlertasCobranca;
use App\Cobranca\Service\CalculadoraHonorarios;
use App\Cobranca\Service\CalculadoraSaldo;
use App\Entity\Tenant\Tenant;

/**
 * Leitura: monta o Dashboard consolidado do escritório (SPEC §20, Etapa 9). É a visão do TENANT, não de
 * um caso — agrega as métricas financeiras, operacionais e de resultado iterando os casos do tenant e
 * reutilizando os serviços de derivação (saldo, honorários, alertas). Nada de regra nova nem de SQL de
 * saldo: o saldo/honorário/alerta continuam derivados por serviço (invariável 20, §18/§19, invariável 28).
 *
 * História: o gestor abre o painel para decidir prioridades — quanto está em aberto/vencido, quanto foi
 * recuperado no período, o que exige ação (pagamentos a verificar, ações atrasadas, parcelas vencidas,
 * revisões) e o resultado acumulado (taxa de recuperação). O tenant e a carteira opcional já chegam
 * resolvidos e tenant-safe do controller; este UseCase não toca HTTP nem persiste nada.
 *
 * Decisões (ver spec da Etapa 9):
 * - Casos ENCERRADOS entram só no acumulado (valor total recuperado); ficam fora de saldo em aberto/vencido,
 *   honorários projetados, contagens operacionais — o que a própria derivação já garante (saldo 0, alertas []).
 * - "Pagamentos a verificar" = casos com alerta `ObrigacaoVencida` (obrigação vencida a verificar, §14).
 * - Honorários realizados por forma (§18): `acrescido_divida` usa o `valorHonorarios` já separado no
 *   pagamento; `retido`/`cobrado_separado` aplicam o percentual sobre a dívida recuperada; `sem_percentual` 0.
 * - Liquidações (não monetárias) contam como recuperação de dívida, mas NÃO geram honorários realizados
 *   (decisão E3; recebimento efetivo é do futuro Financeiro, §19).
 */
final class MontarDashboardCobrancaUseCase
{
    public function __construct(
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly PagamentoRepository $pagamentoRepository,
        private readonly LiquidacaoRepository $liquidacaoRepository,
        private readonly CalculadoraSaldo $calculadoraSaldo,
        private readonly CalculadoraHonorarios $calculadoraHonorarios,
        private readonly AlertasCobranca $alertasCobranca,
    ) {
    }

    public function executar(
        Tenant $tenant,
        ?Carteira $carteira,
        \DateTimeImmutable $inicio,
        \DateTimeImmutable $fim,
        ?\DateTimeImmutable $hoje = null,
    ): DashboardCobrancaOutput {
        $hoje ??= new \DateTimeImmutable('today');

        $casos = $this->casoRepository->doTenant($tenant, $carteira);

        $saldoEmAberto = 0;
        $saldoVencido = 0;
        $valorRecuperadoNoPeriodo = 0;
        $honorariosProjetados = 0;
        $honorariosRealizadosNoPeriodo = 0;
        $valorTotalRecuperado = 0;

        $pagamentosAVerificar = 0;
        $proximasAcoesAtrasadas = 0;
        $parcelasAcordoVencidas = 0;
        $revisoesPendentes = 0;
        $casosJudicializados = 0;

        $casosAtivos = 0;
        $objetosInadimplentes = [];

        foreach ($casos as $caso) {
            $encerrado = $caso->getStatus() === StatusCaso::Encerrado;

            $pagamentos = $this->pagamentoRepository->doCaso($caso);
            $liquidacoes = $this->liquidacaoRepository->doCaso($caso);

            // Resultado acumulado (all-time): pagamentos (parte da dívida) + liquidações reconhecidas.
            foreach ($pagamentos as $pagamento) {
                $valorTotalRecuperado += $pagamento->valorRecuperadoDivida();
            }
            foreach ($liquidacoes as $liquidacao) {
                $valorTotalRecuperado += $liquidacao->getValorReconhecido();
            }

            // Recuperado no período (financeiro): mesmos movimentos, filtrados por data.
            $recuperadoDividaPeriodo = 0;
            $honorariosPagamentoPeriodo = 0;
            foreach ($pagamentos as $pagamento) {
                if (!$this->dentroDoPeriodo($pagamento->getData(), $inicio, $fim)) {
                    continue;
                }
                $recuperadoDividaPeriodo += $pagamento->valorRecuperadoDivida();
                $honorariosPagamentoPeriodo += $pagamento->getValorHonorarios();
            }
            $valorRecuperadoNoPeriodo += $recuperadoDividaPeriodo;
            foreach ($liquidacoes as $liquidacao) {
                if ($this->dentroDoPeriodo($liquidacao->getData(), $inicio, $fim)) {
                    $valorRecuperadoNoPeriodo += $liquidacao->getValorReconhecido();
                }
            }

            // Honorários realizados no período, conforme a forma do snapshot do caso (§18).
            if ($caso->getFormaHonorarios() === FormaHonorarios::AcrescidoDivida) {
                $honorariosRealizadosNoPeriodo += $honorariosPagamentoPeriodo;
            } else {
                $honorariosRealizadosNoPeriodo += $this->calculadoraHonorarios->realizadosSobreRecuperacao(
                    $caso,
                    $recuperadoDividaPeriodo,
                );
            }

            // Encerrado: fora das métricas de "em aberto"/operacionais (saldo 0 e alertas [] por derivação).
            if ($encerrado) {
                continue;
            }

            $saldoExigivelCaso = $this->calculadoraSaldo->saldoExigivel($caso);
            $saldoEmAberto += $saldoExigivelCaso;
            $saldoVencido += $this->calculadoraSaldo->saldoVencido($caso, $hoje);
            $honorariosProjetados += $this->calculadoraHonorarios->projetados($caso, $saldoExigivelCaso);

            ++$casosAtivos;

            if ($saldoExigivelCaso > 0) {
                $objetoId = $caso->getObjeto()?->getId();
                if ($objetoId !== null) {
                    $objetosInadimplentes[$objetoId] = true;
                }
            }

            if ($caso->getStatus() === StatusCaso::Judicializado) {
                ++$casosJudicializados;
            }

            foreach ($this->alertasCobranca->alertasDoCaso($caso, $hoje) as $alerta) {
                match ($alerta->tipo) {
                    TipoAlerta::ObrigacaoVencida => ++$pagamentosAVerificar,
                    TipoAlerta::AcaoAtrasada => ++$proximasAcoesAtrasadas,
                    TipoAlerta::ParcelaAcordoVencida => ++$parcelasAcordoVencidas,
                    TipoAlerta::RevisaoPendente => ++$revisoesPendentes,
                    TipoAlerta::ProntoParaEncerrar => null,
                };
            }
        }

        $emAberto = $saldoEmAberto;
        $baseRecuperacao = $valorTotalRecuperado + $emAberto;
        $taxaRecuperacaoBasisPoints = $baseRecuperacao > 0
            ? intdiv($valorTotalRecuperado * 10000, $baseRecuperacao)
            : 0;

        return new DashboardCobrancaOutput(
            saldoEmAberto: $saldoEmAberto,
            saldoVencido: $saldoVencido,
            valorRecuperadoNoPeriodo: $valorRecuperadoNoPeriodo,
            honorariosProjetados: $honorariosProjetados,
            honorariosRealizadosNoPeriodo: $honorariosRealizadosNoPeriodo,
            pagamentosAVerificar: $pagamentosAVerificar,
            proximasAcoesAtrasadas: $proximasAcoesAtrasadas,
            parcelasAcordoVencidas: $parcelasAcordoVencidas,
            revisoesPendentes: $revisoesPendentes,
            casosJudicializados: $casosJudicializados,
            valorTotalRecuperado: $valorTotalRecuperado,
            valorEmAberto: $emAberto,
            taxaRecuperacaoBasisPoints: $taxaRecuperacaoBasisPoints,
            objetosInadimplentes: count($objetosInadimplentes),
            casosAtivos: $casosAtivos,
            totalCasos: count($casos),
            periodoInicio: $inicio,
            periodoFim: $fim,
            carteiraId: $carteira?->getId(),
        );
    }

    /** Data (date_immutable, sem hora) dentro do período inclusivo [inicio, fim]. */
    private function dentroDoPeriodo(\DateTimeImmutable $data, \DateTimeImmutable $inicio, \DateTimeImmutable $fim): bool
    {
        return $data >= $inicio && $data <= $fim;
    }
}
