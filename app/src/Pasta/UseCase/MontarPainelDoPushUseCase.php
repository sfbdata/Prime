<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Djen\Repository\PublicacaoDjenRepository;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\PainelDoPushOutput;
use App\Pasta\Entity\Pasta;
use App\Processo\DTO\SituacaoAtualOutput;
use App\Processo\Entity\Processo;
use App\Processo\Repository\PrevisaoRegistradaRepository;
use App\Processo\Service\PrevisaoProcessual;
use Psr\Clock\ClockInterface;

/**
 * Monta o cartão da coluna direita da aba Push (spec push-painel-monitoramento §1 e §3).
 *
 * História: quem abre a pasta quer saber, sem ler as publicações uma a uma, se o processo está
 * sendo acompanhado, quando foi a última consulta e em que pé ele está (fase, prazo, próximo ato).
 *
 *   1. sem processo principal: o cartão "Sem monitoramento";
 *   2. com ele: lê a movimentação mais recente da pasta (da mesma lista da aba) e o resumo do
 *      histórico, e calcula a situação atual;
 *   3. taxa de acertos, POR PASTA (o processo pode estar em outra pasta, com outra lista): quando a
 *      ÚLTIMA movimentação mudou desde a previsão anterior, confere a anterior contra o que
 *      aconteceu e grava a nova (dc L.5105-5109). Idempotente por chave: recarregar a aba não grava
 *      nem confere de novo. Pausado ou com a pasta excluída não grava, só lê a taxa.
 */
final class MontarPainelDoPushUseCase
{
    /** O mesmo teto da lista da aba (PastaController::PUSH_LIMITE). */
    private const LIMITE_MOVIMENTACOES = 100;

    public function __construct(
        private readonly PublicacaoDjenRepository $publicacaoRepository,
        private readonly PrevisaoRegistradaRepository $previsaoRepository,
        private readonly PrevisaoProcessual $previsao,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(Pasta $pasta, Tenant $tenant): PainelDoPushOutput
    {
        $principal = $pasta->getProcessoPrincipal();
        if ($principal === null || $principal->getTenant() !== $tenant) {
            return PainelDoPushOutput::semProcesso();
        }

        $agora = $this->relogio->now();
        $situacao = $this->situacaoAtual($pasta, $principal, $tenant, $agora);
        if ($situacao !== null) {
            $situacao = $situacao->comAprendizado($this->aprender($pasta, $principal, $tenant, $situacao, $agora));
        }

        return PainelDoPushOutput::daPasta($pasta, $principal, $agora, $situacao);
    }

    /**
     * Só a mais recente vem com o teor; do histórico, o banco responde quantas são e se alguma
     * fala em contestação (antes, eram até 100 teores de 15 mil caracteres por GET).
     */
    private function situacaoAtual(Pasta $pasta, Processo $principal, Tenant $tenant, \DateTimeImmutable $agora): ?SituacaoAtualOutput
    {
        $numeros = [];
        foreach ($pasta->getPastaProcessos() as $vinculo) {
            $numeros[] = $vinculo->getProcesso()->getNumeroProcesso();
        }

        $ultima = $this->publicacaoRepository->ultimaParaPrevisaoDoTenant($tenant, $numeros);
        if ($ultima === null) {
            return null;
        }

        $historico = $this->publicacaoRepository->historicoParaPrevisaoDoTenant($tenant, $numeros, self::LIMITE_MOVIMENTACOES);

        return $this->previsao->preverComHistorico($ultima, $historico, $principal->getClasseProcessual(), $agora);
    }

    /**
     * O rodapé da taxa de acertos (dc L.5115). Grava só com o monitoramento ativo (o desenho nem
     * calcula a previsão pausado) e com a pasta viva: a pasta excluída é somente leitura.
     */
    private function aprender(Pasta $pasta, Processo $principal, Tenant $tenant, SituacaoAtualOutput $situacao, \DateTimeImmutable $agora): string
    {
        if ($principal->monitoramentoAtivo() && !$pasta->estaExcluida()) {
            $this->registrarPrevisao($pasta, $principal, $tenant, $situacao, $agora);
        }

        $taxa = $this->previsaoRepository->contarConferidas($pasta, $tenant);

        return $taxa['conferidas'] > 0
            ? 'Previsões conferidas: ' . $taxa['acertos'] . ' de ' . $taxa['conferidas'] . ' acertaram'
            : 'Ainda sem previsão anterior para conferir';
    }

    private function registrarPrevisao(Pasta $pasta, Processo $principal, Tenant $tenant, SituacaoAtualOutput $situacao, \DateTimeImmutable $agora): void
    {
        $anterior = $this->previsaoRepository->ultimaDaPasta($pasta, $tenant);
        if ($anterior !== null && $anterior['chave'] === $situacao->chave) {
            return;
        }

        if ($anterior !== null && $anterior['previsto'] !== '' && !$anterior['conferida']) {
            $acertou = PrevisaoProcessual::acertou($anterior['previsto'], $situacao->textoDoUltimo);
            $this->previsaoRepository->conferir($anterior['id'], $tenant, $acertou);
        }

        $this->previsaoRepository->registrar($tenant, $pasta, $principal, $situacao->chave, $situacao->previstoParaConferir, $agora);
    }
}
