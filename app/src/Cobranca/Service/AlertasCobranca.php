<?php

declare(strict_types=1);

namespace App\Cobranca\Service;

use App\Cobranca\DTO\AlertaCobranca;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Enum\TipoAlerta;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Repository\ProximaAcaoRepository;
use App\Cobranca\Repository\RevisaoPessoaCobradaRepository;

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
 * - Revisão pendente alimenta o alerta enquanto `existePendenteDoCaso` for verdadeiro; ao resolver a
 *   revisão o alerta CESSA (SPEC §8) — nada aqui reprocessa o evento original.
 */
final class AlertasCobranca
{
    public function __construct(
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly ProximaAcaoRepository $proximaAcaoRepository,
        private readonly RevisaoPessoaCobradaRepository $revisaoRepository,
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
        $alertas = [];

        $exigiveis = $this->obrigacaoRepository->doCasoExigiveis($caso);
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

        $acaoAtiva = $this->proximaAcaoRepository->findAtivaDoCaso($caso);

        if ($acaoAtiva !== null && $acaoAtiva->estaAtrasada($hoje)) {
            $alertas[] = new AlertaCobranca(
                TipoAlerta::AcaoAtrasada,
                'Próxima ação com prazo vencido.',
            );
        }

        // Saldo exigível zero (e caso não encerrado): pronto para o gestor decidir encerrar (SPEC §14).
        if ($this->calculadoraSaldo->saldoExigivel($caso) === 0) {
            $alertas[] = new AlertaCobranca(
                TipoAlerta::ProntoParaEncerrar,
                'Saldo exigível zerado: pronto para encerrar.',
            );
        }

        // Revisão de pessoa cobrada pendente (§8): cessa assim que a revisão é resolvida.
        if ($this->revisaoRepository->existePendenteDoCaso($caso)) {
            $alertas[] = new AlertaCobranca(
                TipoAlerta::RevisaoPendente,
                'Há revisão de vínculo pendente de decisão.',
            );
        }

        return $alertas;
    }
}
