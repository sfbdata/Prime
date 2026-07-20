<?php

declare(strict_types=1);

namespace App\Cobranca\Service;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\LiquidacaoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Entity\Tenant\Tenant;

/**
 * Deriva o saldo do Caso de Cobrança a partir das obrigações e dos movimentos (SPEC §10,
 * invariável 20): o saldo NUNCA é digitado nem persistido — é sempre calculado dos eventos/valores.
 * Toda aritmética em CENTAVOS inteiros. Serviço read-only (não persiste).
 *
 * Escopo acumulado até a Etapa 4: o exigível é obrigação (original + encargos reconhecidos) MENOS
 * os pagamentos alocados MENOS as liquidações reconhecidas, considerando apenas as obrigações
 * EXIGÍVEIS — `doCasoExigiveis` já exclui as substituídas por acordo vigente e as parcelas de acordo
 * rompido/cancelado (SPEC §12, invariáveis 15/20). A fonte de verdade continua sendo as obrigações e
 * os movimentos; nada de saldo manual.
 */
// Não-final: permite substituição por mock nos testes de UseCase que dependem do saldo derivado
// (ex.: EncerrarCaso exige saldo exigível zero; AlertasCobranca deriva o "pronto para encerrar").
class CalculadoraSaldo
{
    public function __construct(
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly AlocacaoPagamentoRepository $alocacaoRepository,
        private readonly LiquidacaoRepository $liquidacaoRepository,
        private readonly EncargosVivos $encargosVivos,
        private readonly ResolvedorConfigEncargos $resolvedor,
    ) {
    }

    /**
     * Hidrata EM MEMÓRIA (sem flush) os encargos das obrigações VIVAS do caso para HOJE (modelo
     * "encargos ao vivo", spec §6.2/INV-V3): resolve a config do caso 1× e aplica a fórmula sobre a
     * data de referência do relógio injetado. Congeladas (Liquidada/Substituída) são ignoradas — o
     * `EncargosVivos` não as toca. Chamado logo após carregar as obrigações e antes de somar, para
     * que exibição e saldo leiam o MESMO exigível vivo.
     *
     * @param Obrigacao[] $obrigacoes
     */
    private function hidratarVivas(CasoCobranca $caso, array $obrigacoes): void
    {
        if ($obrigacoes === []) {
            return;
        }

        $this->encargosVivos->hidratar($this->resolvedor->resolverDoCaso($caso), $obrigacoes);
    }

    /**
     * Saldo exigível do caso, em centavos: Σ exigível das obrigações − Σ pagamentos alocados
     * − Σ liquidações reconhecidas (SPEC §10/§11).
     */
    public function saldoExigivel(CasoCobranca $caso): int
    {
        $bruto = 0;
        $ids = [];

        $obrigacoes = $this->obrigacaoRepository->doCasoExigiveis($caso);
        $this->hidratarVivas($caso, $obrigacoes);

        foreach ($obrigacoes as $obrigacao) {
            $bruto += $obrigacao->valorExigivel();
            $id = $obrigacao->getId();

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        $tenant = $caso->getTenant();
        // Abate só as alocações às obrigações exigíveis (as substituídas e suas alocações saem juntas).
        $pago = $tenant === null ? 0 : $this->alocacaoRepository->totalAlocadoEmObrigacoes($ids, $tenant);
        $liquidado = $this->liquidacaoRepository->totalReconhecidoNoCaso($caso);

        return $bruto - $pago - $liquidado;
    }

    /**
     * Saldo vencido do caso, em centavos: exigível das obrigações vencidas até hoje (inclusive),
     * abatido dos pagamentos alocados a essas obrigações e das liquidações do caso (que amortizam
     * o vencido primeiro), com piso 0. `$hoje` é injetável para testes determinísticos.
     */
    public function saldoVencido(CasoCobranca $caso, ?\DateTimeImmutable $hoje = null): int
    {
        // Fonte de tempo ÚNICA: o mesmo relógio da hidratação (spec §5), para a data do vencido não
        // divergir da data do encargo.
        $hoje ??= $this->encargosVivos->agora();
        $bruto = 0;
        $idsVencidas = [];

        $obrigacoes = $this->obrigacaoRepository->doCasoExigiveis($caso);
        $this->hidratarVivas($caso, $obrigacoes);

        foreach ($obrigacoes as $obrigacao) {
            if ($obrigacao->getVencimentoOriginal() <= $hoje) {
                $bruto += $obrigacao->valorExigivel();
                $idVencida = $obrigacao->getId();

                if ($idVencida !== null) {
                    $idsVencidas[] = $idVencida;
                }
            }
        }

        $tenant = $caso->getTenant();

        $pagoVencidas = $tenant === null
            ? 0
            : $this->alocacaoRepository->totalAlocadoEmObrigacoes($idsVencidas, $tenant);
        $liquidado = $this->liquidacaoRepository->totalReconhecidoNoCaso($caso);

        return max(0, $bruto - $pagoVencidas - $liquidado);
    }

    /**
     * Saldo consolidado do objeto (SPEC §6, modo B): soma do exigível de TODOS os casos ativos do
     * objeto, em centavos. Nenhum caso isolado representa o total do objeto.
     */
    public function saldoConsolidadoObjeto(ObjetoCobranca $objeto): int
    {
        $total = 0;

        foreach ($this->casoRepository->casosAtivosDoObjeto($objeto) as $caso) {
            $total += $this->saldoExigivel($caso);
        }

        return $total;
    }

    /**
     * Núcleo PURO da derivação de saldo (SPEC §10/§11, invariável 20): a partir das obrigações EXIGÍVEIS
     * já carregadas de um caso, do mapa `obrigacaoId => Σ alocado` e do total liquidado reconhecido do
     * caso, deriva o saldo exigível e o vencido — a MESMA regra de `saldoExigivel`/`saldoVencido` acima
     * (exigível = bruto − alocado − liquidado; vencido com piso 0), só que sem I/O. É o ponto reutilizado
     * pela agregação em LOTE do Dashboard (Etapa 9), que carrega obrigações/alocações/liquidações do tenant
     * de uma vez e chama isto por caso — evitando o N+1 dos métodos por-caso.
     *
     * @param \App\Cobranca\Entity\Obrigacao[] $exigiveis           obrigações EXIGÍVEIS do caso (já filtradas)
     * @param array<int, int>                  $alocadoPorObrigacao obrigacaoId => Σ alocado (centavos)
     *
     * @return array{exigivel: int, vencido: int}
     */
    public function derivarSaldos(array $exigiveis, array $alocadoPorObrigacao, int $totalLiquidado, \DateTimeImmutable $hoje): array
    {
        $bruto = 0;
        $brutoVencido = 0;
        $alocadoTotal = 0;
        $alocadoVencidas = 0;

        foreach ($exigiveis as $obrigacao) {
            $valor = $obrigacao->valorExigivel();
            $id = $obrigacao->getId();
            $alocado = $id !== null ? ($alocadoPorObrigacao[$id] ?? 0) : 0;

            $bruto += $valor;
            $alocadoTotal += $alocado;

            if ($obrigacao->getVencimentoOriginal() <= $hoje) {
                $brutoVencido += $valor;
                $alocadoVencidas += $alocado;
            }
        }

        return [
            'exigivel' => $bruto - $alocadoTotal - $totalLiquidado,
            'vencido' => max(0, $brutoVencido - $alocadoVencidas - $totalLiquidado),
        ];
    }

    /**
     * Deriva os saldos (exigível/vencido) de VÁRIOS casos a partir de datasets JÁ CARREGADOS em lote —
     * é o núcleo de ORQUESTRAÇÃO sem I/O, reaproveitado tanto por `saldosDosCasos` (que carrega e delega)
     * quanto pelo Dashboard (que já tem os datasets em mãos e não deve recarregá-los). Aplica a MESMA regra
     * pura `derivarSaldos` por caso — nenhuma regra nova. `$alocadoPorObrigacao` é global (obrigacaoId => Σ
     * alocado); `$liquidadoPorCaso` é o Σ reconhecido de liquidações por caso.
     *
     * @param CasoCobranca[]                                $casos
     * @param array<int, \App\Cobranca\Entity\Obrigacao[]>  $exigiveisPorCaso    casoId => obrigações exigíveis
     * @param array<int, int>                               $alocadoPorObrigacao obrigacaoId => Σ alocado (centavos)
     * @param array<int, int>                               $liquidadoPorCaso    casoId => Σ liquidação reconhecida (centavos)
     *
     * @return array<int, array{exigivel: int, vencido: int}> casoId => saldos
     */
    public function derivarSaldosDosCasos(
        array $casos,
        array $exigiveisPorCaso,
        array $alocadoPorObrigacao,
        array $liquidadoPorCaso,
        \DateTimeImmutable $hoje,
    ): array {
        $saldos = [];

        foreach ($casos as $caso) {
            $casoId = $caso->getId() ?? 0;
            $saldos[$casoId] = $this->derivarSaldos(
                $exigiveisPorCaso[$casoId] ?? [],
                $alocadoPorObrigacao,
                $liquidadoPorCaso[$casoId] ?? 0,
                $hoje,
            );
        }

        return $saldos;
    }

    /**
     * Saldos (exigível/vencido) de VÁRIOS casos numa carga em LOTE — evita o N+1 de chamar
     * `saldoExigivel`/`saldoVencido` por caso (fonte do gargalo nas telas tenant-wide e por carteira).
     * Carrega, de UMA vez para todos os casos, as obrigações exigíveis, as somas de alocação por obrigação
     * e as liquidações; agrupa e deriva via `derivarSaldosDosCasos` (mesma regra dos métodos por-caso, que
     * ficam intactos). Tenant SEMPRE explícito nas cargas; `$casos` vazio → `[]`. `$hoje` injetável para
     * testes determinísticos (default = agora), igual ao `saldoVencido` por-caso.
     *
     * @param CasoCobranca[] $casos
     *
     * @return array<int, array{exigivel: int, vencido: int}> casoId => saldos
     */
    public function saldosDosCasos(array $casos, Tenant $tenant, ?\DateTimeImmutable $hoje = null): array
    {
        if ($casos === []) {
            return [];
        }

        // Fonte de tempo ÚNICA: o mesmo relógio da hidratação (spec §5).
        $hoje ??= $this->encargosVivos->agora();

        $casoIds = [];
        foreach ($casos as $caso) {
            $id = $caso->getId();
            if ($id !== null) {
                $casoIds[] = $id;
            }
        }

        $exigiveisPorCaso = [];
        foreach ($this->obrigacaoRepository->exigiveisDosCasos($casoIds, $tenant) as $obrigacao) {
            $casoId = $obrigacao->getCaso()?->getId();
            if ($casoId !== null) {
                $exigiveisPorCaso[$casoId][] = $obrigacao;
            }
        }

        // Hidrata as VIVAS de cada caso (config resolvida 1× por caso) antes de derivar — a mesma
        // regra ao vivo dos métodos por-caso, sem reintroduzir N+1 na carga em lote.
        foreach ($casos as $caso) {
            $casoId = $caso->getId();
            if ($casoId !== null && isset($exigiveisPorCaso[$casoId])) {
                $this->hidratarVivas($caso, $exigiveisPorCaso[$casoId]);
            }
        }

        $alocadoPorObrigacao = $this->alocacaoRepository->somasPorObrigacaoDosCasos($casoIds, $tenant);

        $liquidadoPorCaso = [];
        foreach ($this->liquidacaoRepository->dosCasos($casoIds, $tenant) as $liquidacao) {
            $casoId = $liquidacao->getCaso()?->getId();
            if ($casoId !== null) {
                $liquidadoPorCaso[$casoId] = ($liquidadoPorCaso[$casoId] ?? 0) + $liquidacao->getValorReconhecido();
            }
        }

        return $this->derivarSaldosDosCasos($casos, $exigiveisPorCaso, $alocadoPorObrigacao, $liquidadoPorCaso, $hoje);
    }
}
