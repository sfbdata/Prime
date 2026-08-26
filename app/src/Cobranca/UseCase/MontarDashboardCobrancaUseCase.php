<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\DashboardCobrancaOutput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\LiquidacaoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Repository\PagamentoRepository;
use App\Cobranca\Repository\ProximaAcaoRepository;
use App\Cobranca\Service\AlertasCobranca;
use App\Cobranca\Service\CalculadoraHonorarios;
use App\Cobranca\Service\CalculadoraSaldo;
use App\Cobranca\Service\EncargosVivos;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use App\Entity\Tenant\Tenant;

/**
 * Leitura: monta o Dashboard consolidado do escritório (SPEC §20, Etapa 9). É a visão do TENANT, não de
 * um caso — agrega as métricas financeiras, operacionais e de resultado reutilizando os serviços de
 * derivação (saldo, honorários). Nada de regra nova nem de SQL de saldo: o saldo continua derivado
 * (invariável 20, §18/§19, invariável 28).
 *
 * PERFORMANCE (batch-load): a agregação NÃO chama os serviços por caso (o que gerava um N+1 de ~14
 * queries × N casos). Em vez disso carrega, de UMA vez para todos os casos do tenant, as obrigações
 * exigíveis, as somas de alocação por obrigação, as liquidações, os pagamentos, as próximas ações
 * pendentes e as revisões pendentes (≈7 queries no total), e computa tudo em memória — o saldo pela regra
 * PURA `CalculadoraSaldo::derivarSaldos` (mesma regra dos métodos por-caso) e os honorários por
 * `CalculadoraHonorarios`. As contagens operacionais espelham os alertas de `AlertasCobranca` (obrigação
 * vencida, parcela de acordo vencida, ação atrasada, revisão pendente), verificado por teste de
 * consistência.
 *
 * Limite conhecido: as cargas em lote usam `caso IN (:casoIds)`; com dezenas de milhares de casos num
 * tenant o teto de bind params do Postgres (~65535) seria atingido — ponto de evolução (chunk dos ids ou
 * agregação materializada), fora do escopo do MVP.
 *
 * História: o gestor abre o painel para decidir prioridades — quanto está em aberto/vencido, quanto foi
 * recuperado no período, o que exige ação e o resultado acumulado (taxa de recuperação). O tenant e a
 * carteira opcional já chegam resolvidos e tenant-safe do controller; este UseCase não toca HTTP nem persiste.
 *
 * Decisões (ver spec da Etapa 9):
 * - Casos ENCERRADOS entram só no acumulado (valor total recuperado); ficam fora de saldo em aberto/vencido,
 *   honorários projetados e contagens operacionais.
 * - "Pagamentos a verificar" = casos com obrigação vencida a verificar (§14).
 * - Honorários realizados por forma (§18): `acrescido_divida` usa o `valorHonorarios` já separado no
 *   pagamento; `retido`/`cobrado_separado` aplicam o percentual sobre a dívida recuperada; `sem_percentual` 0.
 * - Liquidações (não monetárias) contam como recuperação, mas NÃO geram honorários realizados (decisão E3).
 */
final class MontarDashboardCobrancaUseCase
{
    public function __construct(
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly AlocacaoPagamentoRepository $alocacaoRepository,
        private readonly PagamentoRepository $pagamentoRepository,
        private readonly LiquidacaoRepository $liquidacaoRepository,
        private readonly ProximaAcaoRepository $proximaAcaoRepository,
        private readonly CalculadoraSaldo $calculadoraSaldo,
        private readonly CalculadoraHonorarios $calculadoraHonorarios,
        private readonly EncargosVivos $encargosVivos,
        private readonly ResolvedorConfigEncargos $resolvedorConfig,
        // Não é para MONTAR alertas aqui (o painel conta casos, não lista alertas): é para reusar o
        // predicado `vencidaEmAberto`, a régua única do que conta como vencido em aberto.
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
        $casoIds = [];
        foreach ($casos as $caso) {
            $id = $caso->getId();
            if ($id !== null) {
                $casoIds[] = $id;
            }
        }

        // ── Carga em LOTE (uma query cada) para todos os casos do tenant ──────────────────────────
        $exigiveisPorCaso = $this->agruparPorCaso($this->obrigacaoRepository->exigiveisDosCasos($casoIds, $tenant));

        // Encargos AO VIVO (spec §6.2/INV-V5): hidrata EM MEMÓRIA as obrigacoes VIVAS de cada caso para
        // HOJE antes de derivar/agregar — o Dashboard soma o MESMO exigivel vivo que o saldo e a tela.
        // Config resolvida 1× por caso; `doTenant` já fez fetch-join de objeto/carteira → sem N+1. As
        // congeladas mantem o snapshot. Read-only (sem flush): nada de Viva e persistido aqui.
        foreach ($casos as $caso) {
            $casoId = $caso->getId();
            if ($casoId !== null && isset($exigiveisPorCaso[$casoId])) {
                $this->encargosVivos->hidratar($this->resolvedorConfig->resolverDoCaso($caso), $exigiveisPorCaso[$casoId]);
            }
        }

        $alocadoPorObrigacao = $this->alocacaoRepository->somasPorObrigacaoDosCasos($casoIds, $tenant);
        $pagamentosPorCaso = $this->agruparPorCaso($this->pagamentoRepository->dosCasos($casoIds, $tenant));
        $liquidacoesPorCaso = $this->agruparPorCaso($this->liquidacaoRepository->dosCasos($casoIds, $tenant));
        $acoesPorCaso = $this->proximaAcaoRepository->ativasDosCasos($casoIds, $tenant);

        // Σ reconhecido de liquidações por caso (a partir do lote já carregado — sem query extra).
        $liquidadoPorCaso = [];
        foreach ($liquidacoesPorCaso as $cid => $liquidacoesDoCaso) {
            $soma = 0;
            foreach ($liquidacoesDoCaso as $liquidacao) {
                $soma += $liquidacao->getValorReconhecido();
            }
            $liquidadoPorCaso[$cid] = $soma;
        }

        // Saldos derivados em LOTE reusando a primitiva compartilhada (mesma regra pura `derivarSaldos`),
        // sobre os datasets já em memória — nenhuma carga adicional (mantém a contagem de queries do Dashboard).
        $saldosPorCaso = $this->calculadoraSaldo->derivarSaldosDosCasos(
            $casos,
            $exigiveisPorCaso,
            $alocadoPorObrigacao,
            $liquidadoPorCaso,
            $hoje,
        );

        $saldoEmAberto = 0;
        $saldoVencido = 0;
        $valorRecuperadoNoPeriodo = 0;
        $honorariosProjetados = 0;
        $honorariosRealizadosNoPeriodo = 0;
        $valorTotalRecuperado = 0;

        $pagamentosAVerificar = 0;
        $proximasAcoesAtrasadas = 0;
        $parcelasAcordoVencidas = 0;
        $casosJudicializados = 0;

        $casosAtivos = 0;
        $objetosInadimplentes = [];

        foreach ($casos as $caso) {
            $casoId = $caso->getId() ?? 0;
            $encerrado = $caso->getStatus() === StatusCaso::Encerrado;

            $pagamentos = $pagamentosPorCaso[$casoId] ?? [];
            $liquidacoes = $liquidacoesPorCaso[$casoId] ?? [];

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

            // Honorários realizados no período, conforme a forma EFETIVA da carteira/objeto do caso
            // (§18; #9-T2 — não mais o snapshot do caso).
            //
            // ⚠️ INTOCADO pela spec `cobranca-honorario-no-total.md`, de propósito. A decisão A aposenta
            // o RATEIO DO PAGAMENTO e a PROJEÇÃO sobre o saldo (que passou a contar o honorário duas
            // vezes). A apuração do realizado por forma não é nenhum dos dois: em `acrescido_divida`
            // — a forma das três carteiras reais — ela já lê o `valorHonorarios` DECLARADO pela
            // contabilidade, que é exatamente o que o espelho pede. Mexer no ramo `retido_recuperado`
            // seria mudar comportamento fora do que foi decidido.
            if ($this->calculadoraHonorarios->forma($caso) === FormaHonorarios::AcrescidoDivida) {
                $honorariosRealizadosNoPeriodo += $honorariosPagamentoPeriodo;
            } else {
                $honorariosRealizadosNoPeriodo += $this->calculadoraHonorarios->realizadosSobreRecuperacao(
                    $caso,
                    $recuperadoDividaPeriodo,
                );
            }

            // Encerrado: fora das métricas de "em aberto"/operacionais (saldo 0 e sem alertas).
            if ($encerrado) {
                continue;
            }

            $exigiveis = $exigiveisPorCaso[$casoId] ?? [];
            $saldos = $saldosPorCaso[$casoId] ?? ['exigivel' => 0, 'vencido' => 0];
            $saldoExigivelCaso = $saldos['exigivel'];

            $saldoEmAberto += $saldoExigivelCaso;
            $saldoVencido += $saldos['vencido'];
            // Honorários PROJETADOS: a Σ do honorário JÁ MATERIALIZADO nas dívidas em aberto — o mesmo
            // número que a contabilidade traz na coluna de honorários da inadimplência.
            //
            // Era `projetados($caso, $saldoExigivelCaso)`, uma alíquota lisa sobre o saldo. Duas razões
            // para sair (spec §4.3): (1) o saldo agora JÁ contém honorário, então a alíquota o contaria
            // duas vezes; (2) medido contra o rodapé dela, a alíquota lisa erra R$ 46.747,38 (37% a
            // mais) porque ignora a carência de 30 dias e as parcelas de acordo — o honorário gravado
            // erra R$ 515,32 (0,4%).
            foreach ($exigiveis as $obrigacaoEmAberto) {
                if (!$obrigacaoEmAberto->estaLiquidada()) {
                    $honorariosProjetados += $obrigacaoEmAberto->getHonorarios();
                }
            }

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

            // Contagens operacionais = espelho dos alertas de AlertasCobranca (um por caso, por condição).
            //
            // A régua vem de `AlertasCobranca::vencidaEmAberto`, NÃO de uma cópia local: enquanto esta
            // contagem repetia o critério à mão, a cópia ficou para trás quando o alerta passou a
            // descartar obrigação já paga (07/08) — e o card "Pagamentos a verificar", que LINKA para a
            // Central de Alertas, passaria a mostrar um número maior que o da lista que ele abre.
            //
            // A hidratação exigida por `vencidaEmAberto` já aconteceu: o laço de `encargosVivos->hidratar`
            // lá em cima percorre `$exigiveisPorCaso` antes de qualquer agregação, e são estas MESMAS
            // instâncias que chegam aqui.
            $temVencida = false;
            $temParcelaVencida = false;
            foreach ($exigiveis as $obrigacao) {
                if (!$this->alertasCobranca->vencidaEmAberto($obrigacao, $alocadoPorObrigacao, $hoje)) {
                    continue;
                }
                $temVencida = true;
                if ($obrigacao->getAcordoOrigem() !== null) {
                    $temParcelaVencida = true;
                }
            }

            if ($temVencida) {
                ++$pagamentosAVerificar;
            }
            if ($temParcelaVencida) {
                ++$parcelasAcordoVencidas;
            }

            $acao = $acoesPorCaso[$casoId] ?? null;
            if ($acao !== null && $acao->estaAtrasada($hoje)) {
                ++$proximasAcoesAtrasadas;
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

    /**
     * Agrupa entidades que expõem `getCaso()` por id do caso.
     *
     * @param array<int, object> $itens
     *
     * @return array<int, list<object>>
     */
    private function agruparPorCaso(array $itens): array
    {
        $grupos = [];
        foreach ($itens as $item) {
            $casoId = $item->getCaso()?->getId();
            if ($casoId !== null) {
                $grupos[$casoId][] = $item;
            }
        }

        return $grupos;
    }

    /** Data (date_immutable, sem hora) dentro do período inclusivo [inicio, fim]. */
    private function dentroDoPeriodo(\DateTimeImmutable $data, \DateTimeImmutable $inicio, \DateTimeImmutable $fim): bool
    {
        return $data >= $inicio && $data <= $fim;
    }
}
