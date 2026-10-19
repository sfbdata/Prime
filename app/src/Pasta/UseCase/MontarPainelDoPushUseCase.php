<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Djen\Repository\PublicacaoDjenRepository;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\PainelDoPushOutput;
use App\Pasta\Entity\Pasta;
use App\Processo\DTO\MovimentacaoParaPrevisao;
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
 *   2. com ele: lê as publicações da pasta (a mesma lista da aba) e calcula a situação atual;
 *   3. taxa de acertos: quando a ÚLTIMA movimentação mudou desde a previsão anterior, confere a
 *      anterior contra o que aconteceu e grava a nova (dc L.5105-5109). Idempotente por chave:
 *      recarregar a aba não grava nem confere de novo. Com o monitoramento pausado não grava
 *      (o desenho nem calcula a previsão nesse estado), só lê a taxa.
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
        $situacao = $this->previsao->prever($this->movimentacoesDaPasta($pasta, $tenant), $principal->getClasseProcessual(), $agora);
        if ($situacao !== null) {
            $situacao = $situacao->comAprendizado($this->aprender($principal, $tenant, $situacao, $agora));
        }

        return PainelDoPushOutput::doProcesso($principal, $agora, $situacao);
    }

    /** @return MovimentacaoParaPrevisao[] */
    private function movimentacoesDaPasta(Pasta $pasta, Tenant $tenant): array
    {
        $numeros = [];
        foreach ($pasta->getPastaProcessos() as $vinculo) {
            $numeros[] = $vinculo->getProcesso()->getNumeroProcesso();
        }

        return $this->publicacaoRepository->listarParaPrevisaoDoTenant($tenant, $numeros, self::LIMITE_MOVIMENTACOES);
    }

    /** O rodapé da taxa de acertos (dc L.5115). */
    private function aprender(Processo $processo, Tenant $tenant, SituacaoAtualOutput $situacao, \DateTimeImmutable $agora): string
    {
        if ($processo->monitoramentoAtivo()) {
            $this->registrarPrevisao($processo, $tenant, $situacao, $agora);
        }

        $taxa = $this->previsaoRepository->contarConferidas($processo, $tenant);

        return $taxa['conferidas'] > 0
            ? 'Previsões conferidas: ' . $taxa['acertos'] . ' de ' . $taxa['conferidas'] . ' acertaram'
            : 'Ainda sem previsão anterior para conferir';
    }

    private function registrarPrevisao(Processo $processo, Tenant $tenant, SituacaoAtualOutput $situacao, \DateTimeImmutable $agora): void
    {
        $anterior = $this->previsaoRepository->ultimaDoProcesso($processo, $tenant);
        if ($anterior !== null && $anterior['chave'] === $situacao->chave) {
            return;
        }

        if ($anterior !== null && $anterior['previsto'] !== '' && !$anterior['conferida']) {
            $acertou = PrevisaoProcessual::acertou($anterior['previsto'], $situacao->textoDoUltimo);
            $this->previsaoRepository->conferir($anterior['id'], $tenant, $acertou);
        }

        $this->previsaoRepository->registrar($tenant, $processo, $situacao->chave, $situacao->previstoParaConferir, $agora);
    }
}
