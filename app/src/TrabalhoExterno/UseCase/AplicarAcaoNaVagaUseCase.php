<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\Tenant\Service\SituacaoDaConta;
use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Enum\AcaoVaga;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\TransicaoInvalida;
use App\TrabalhoExterno\Repository\CandidaturaRepository;
use App\TrabalhoExterno\Repository\VagaRepository;
use App\TrabalhoExterno\Service\AvisosDoTrabalhoExterno;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeVaga;
use App\TrabalhoExterno\Service\PoliticaDaVaga;

/**
 * Aprovar e publicar, não aprovar, pausar inscrições, reabrir, ofertar novamente e encerrar (desenho
 * 06 l.979–983). Só a Central. Fluxo: localiza no escopo (404); confere a política (403); a entidade
 * grava o vencimento que estava só calculado e confere a situação (409). A aprovação aplica o teto de
 * dias e, se o Master não escolheu o tipo, o tempo padrão da oferta da configuração. Tudo com a linha
 * da vaga travada, como o aceite da oferta: pausar ou encerrar não sobrescreve o "Preenchida" de um
 * aceite simultâneo (a ação relê a vaga e confere a situação já preenchida). Avisos (spec §4.7): o
 * dono sabe da aprovação e da não aprovação; quem ligou o aviso da área, da vaga aprovada, reaberta ou
 * ofertada de novo.
 */
final class AplicarAcaoNaVagaUseCase
{
    public function __construct(
        private readonly LocalizadorDeVaga $localizador,
        private readonly PoliticaDaVaga $politica,
        private readonly VagaRepository $vagas,
        private readonly CandidaturaRepository $candidaturas,
        private readonly AvisosDoTrabalhoExterno $avisos,
        private readonly SituacaoDaConta $situacao,
    ) {
    }

    public function executar(Contexto $ctx, string $codigo, AcaoVaga $acao, ?string $motivo): Vaga
    {
        $agora = new \DateTimeImmutable();
        $vaga = $this->localizador->localizar($ctx, $codigo, $agora);
        if (!$this->politica->podeExecutar($ctx, $vaga, $acao)) {
            throw new AcaoNaoPermitida('Você não pode executar esta ação nesta vaga.');
        }
        $this->exigirEscritorioNoArParaPublicar($vaga, $acao);

        $falha = $this->vagas->comAVagaTravada($vaga, fn (): ?TransicaoInvalida => $this->aplicar($ctx, $vaga, $acao, $motivo, $agora));

        return $falha !== null ? throw $falha : $vaga;
    }

    /**
     * Aprovar, reabrir e ofertar de novo publicam a vaga (e avisam quem tem alerta na área): com o escritório que a
     * divulgou fora do ar, não (decisão do coordenador em 20/10, até o dono responder; docs/specs/te-convidado-escritorio-fora-do-ar.md).
     */
    private function exigirEscritorioNoArParaPublicar(Vaga $vaga, AcaoVaga $acao): void
    {
        $dono = $vaga->getEscritorio();
        if ($dono !== null && $this->situacao->foraDoAr($dono)
            && in_array($acao, [AcaoVaga::Aprovar, AcaoVaga::Reabrir, AcaoVaga::OfertarNovamente], true)) {
            throw new AcaoNaoPermitida('O escritório que divulgou esta vaga está fora do ar. A vaga não pode ser publicada agora.');
        }
    }

    /** Dentro da trava: a recusa volta como valor, para não fechar o EntityManager. */
    private function aplicar(Contexto $ctx, Vaga $vaga, AcaoVaga $acao, ?string $motivo, \DateTimeImmutable $agora): ?TransicaoInvalida
    {
        $cfg = $ctx->configuracao->getVagas();
        // Os aceites da rodada vão para a linha "Oferta expirou com N de Q" quando o vencimento é gravado aqui.
        $aceites = $vaga->isOferta() ? count($this->candidaturas->aceitesDaRodada($vaga)) : 0;
        try {
            $vaga->aplicar($acao, $motivo, $ctx->autor()->nome, $cfg['dur'], $cfg['maxDias'], $agora, $aceites);
        } catch (TransicaoInvalida $e) {
            return $e;
        }
        $this->avisar($ctx, $vaga, $acao, $agora);

        return null;
    }

    private function avisar(Contexto $ctx, Vaga $vaga, AcaoVaga $acao, \DateTimeImmutable $agora): void
    {
        if ($acao === AcaoVaga::Aprovar) {
            $this->avisos->vagaAprovada($ctx, $vaga);
        }
        if ($acao === AcaoVaga::Reprovar) {
            $this->avisos->vagaReprovada($ctx, $vaga);
        }
        if (in_array($acao, [AcaoVaga::Aprovar, AcaoVaga::Reabrir, AcaoVaga::OfertarNovamente], true)) {
            $this->avisos->vagaNaArea($ctx, $vaga, $agora);
        }
    }
}
