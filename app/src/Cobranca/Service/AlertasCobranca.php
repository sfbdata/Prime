<?php

declare(strict_types=1);

namespace App\Cobranca\Service;

use App\Cobranca\DTO\AlertaCobranca;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Entity\ProximaAcao;
use App\Cobranca\Enum\TipoAlerta;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Repository\ProximaAcaoRepository;
use App\Entity\Tenant\Tenant;

/**
 * Deriva os alertas operacionais de um Caso de Cobrança (SPEC §14, invariável 28: o sistema alerta,
 * o humano decide). Serviço READ-ONLY: nunca persiste, nunca muda estado — apenas lê fatos do caso
 * (obrigações exigíveis, próxima ação, saldo derivado, revisão pendente) e devolve uma lista de
 * `AlertaCobranca` recalculada a cada chamada.
 *
 * Decisões documentadas:
 * - Caso ENCERRADO não gera alertas operacionais (retorna lista vazia): encerrado é estado final,
 *   sem ação pendente que faça sentido cobrar.
 * - Obrigações vencidas são AGREGADAS num único alerta com a contagem na descrição (evita poluir a
 *   tela com um alerta por parcela); o mesmo vale para parcelas de acordo vencidas.
 */
final class AlertasCobranca
{
    public function __construct(
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly ProximaAcaoRepository $proximaAcaoRepository,
        private readonly CalculadoraSaldo $calculadoraSaldo,
    ) {
    }

    /**
     * Alertas derivados do caso na data de referência `$hoje` (injetável para testes determinísticos;
     * default = agora). Cada condição verdadeira vira um `AlertaCobranca`.
     *
     * @return AlertaCobranca[]
     */
    public function alertasDoCaso(CasoCobranca $caso, ?\DateTimeImmutable $hoje = null): array
    {
        // Caso encerrado é estado final: não faz sentido alertar sobre ações operacionais.
        if ($caso->estaEncerrado()) {
            return [];
        }

        $hoje ??= new \DateTimeImmutable();

        // Carga por-caso (usada pelo detalhe do caso; mantém o N+1 aceitável de 1 caso só).
        return $this->montarAlertas(
            $this->obrigacaoRepository->doCasoExigiveis($caso),
            $this->proximaAcaoRepository->findAtivaDoCaso($caso),
            $this->calculadoraSaldo->saldoExigivel($caso),
            $hoje,
        );
    }

    /**
     * Como `alertasDoCaso`, mas reaproveitando o `saldoExigivel` e a ação ativa que o chamador JÁ computou
     * (dedupe — evita recalcular o saldo e re-buscar a ação dentro deste serviço). Usado pelo Detalhe do Caso
     * (`MontarDetalheCasoUseCase`), que precisa desses dois valores para o cabeçalho e a próxima ação. Mesma
     * regra de `alertasDoCaso` (via `montarAlertas`); caso encerrado → `[]`. `$hoje` injetável.
     *
     * @return AlertaCobranca[]
     */
    public function alertasComContexto(
        CasoCobranca $caso,
        int $saldoExigivel,
        ?ProximaAcao $acaoAtiva,
        ?\DateTimeImmutable $hoje = null,
    ): array {
        if ($caso->estaEncerrado()) {
            return [];
        }

        $hoje ??= new \DateTimeImmutable();

        return $this->montarAlertas(
            $this->obrigacaoRepository->doCasoExigiveis($caso),
            $acaoAtiva,
            $saldoExigivel,
            $hoje,
        );
    }

    /**
     * Versão em LOTE de `alertasDoCaso` para a visão tenant-wide (Central de Alertas, Etapa 9) — evita o
     * N+1 de ~6 queries por caso. Carrega de UMA vez, para todos os casos: obrigações exigíveis, ações
     * pendentes, revisões pendentes e os saldos (via `CalculadoraSaldo::saldosDosCasos`), e deriva os
     * MESMOS alertas por caso via `montarAlertas` — mesma regra de `alertasDoCaso`, que fica intacto.
     * Caso encerrado → `[]` (idêntico). Tenant SEMPRE explícito nas cargas; `$casos` vazio → `[]`.
     * `$hoje` injetável (default = agora).
     *
     * @param CasoCobranca[] $casos
     *
     * @return array<int, AlertaCobranca[]> casoId => alertas
     */
    public function alertasDosCasos(array $casos, Tenant $tenant, ?\DateTimeImmutable $hoje = null): array
    {
        if ($casos === []) {
            return [];
        }

        $hoje ??= new \DateTimeImmutable();

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

        $acoesPorCaso = $this->proximaAcaoRepository->ativasDosCasos($casoIds, $tenant);
        $saldosPorCaso = $this->calculadoraSaldo->saldosDosCasos($casos, $tenant, $hoje);

        $resultado = [];
        foreach ($casos as $caso) {
            $casoId = $caso->getId() ?? 0;

            // Encerrado é estado final: sem alertas operacionais (mesma decisão de `alertasDoCaso`).
            if ($caso->estaEncerrado()) {
                $resultado[$casoId] = [];

                continue;
            }

            $resultado[$casoId] = $this->montarAlertas(
                $exigiveisPorCaso[$casoId] ?? [],
                $acoesPorCaso[$casoId] ?? null,
                $saldosPorCaso[$casoId]['exigivel'] ?? 0,
                $hoje,
            );
        }

        return $resultado;
    }

    /**
     * Regra ÚNICA de derivação dos alertas operacionais, a partir de fatos JÁ RESOLVIDOS do caso (SPEC §14).
     * Fonte compartilhada por `alertasDoCaso` (per-caso) e `alertasDosCasos` (lote) — nenhuma regra duplicada.
     *
     * @param Obrigacao[] $exigiveis obrigações EXIGÍVEIS do caso
     *
     * @return AlertaCobranca[]
     */
    private function montarAlertas(
        array $exigiveis,
        ?ProximaAcao $acaoAtiva,
        int $saldoExigivel,
        \DateTimeImmutable $hoje,
    ): array {
        $alertas = [];
        $vencidas = 0;
        $parcelasVencidas = 0;

        foreach ($exigiveis as $obrigacao) {
            if ($obrigacao->getVencimentoOriginal() > $hoje) {
                continue;
            }

            ++$vencidas;

            // Parcela de acordo vencida: obrigação vencida cujo acordo de origem existe (SPEC §12).
            if ($obrigacao->getAcordoOrigem() !== null) {
                ++$parcelasVencidas;
            }
        }

        if ($vencidas > 0) {
            $alertas[] = new AlertaCobranca(
                TipoAlerta::ObrigacaoVencida,
                sprintf('%d obrigação(ões) exigível(is) vencida(s) a verificar.', $vencidas),
            );
        }

        if ($parcelasVencidas > 0) {
            $alertas[] = new AlertaCobranca(
                TipoAlerta::ParcelaAcordoVencida,
                sprintf('%d parcela(s) de acordo vencida(s).', $parcelasVencidas),
            );
        }

        if ($acaoAtiva !== null && $acaoAtiva->estaAtrasada($hoje)) {
            $alertas[] = new AlertaCobranca(
                TipoAlerta::AcaoAtrasada,
                'Próxima ação com prazo vencido.',
            );
        }

        // Saldo exigível zero (e caso não encerrado): pronto para o gestor decidir encerrar (SPEC §14).
        if ($saldoExigivel === 0) {
            $alertas[] = new AlertaCobranca(
                TipoAlerta::ProntoParaEncerrar,
                'Saldo exigível zerado: pronto para encerrar.',
            );
        }

        return $alertas;
    }
}
