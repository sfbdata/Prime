<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Enum\AcaoVaga;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Repository\VagaRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeVaga;
use App\TrabalhoExterno\Service\PoliticaDaVaga;

/**
 * Aprovar, não aprovar, pausar, retomar, marcar como preenchida e encerrar (spec VS 18.10.26 §4.1).
 * Fluxo: localiza no escopo (404); confere a política (403); a entidade grava o vencimento que
 * estava só calculado e confere a situação e o motivo (409).
 */
final class AplicarAcaoNaVagaUseCase
{
    public function __construct(
        private readonly LocalizadorDeVaga $localizador,
        private readonly PoliticaDaVaga $politica,
        private readonly VagaRepository $vagas,
    ) {
    }

    public function executar(Contexto $ctx, string $codigo, AcaoVaga $acao, ?string $motivo): Vaga
    {
        $agora = new \DateTimeImmutable();
        $vaga = $this->localizador->localizar($ctx, $codigo, $agora);
        if (!$this->politica->podeExecutar($ctx, $vaga, $acao)) {
            throw new AcaoNaoPermitida('Você não pode executar esta ação nesta vaga.');
        }

        // "Pedidos acima do limite são ajustados na aprovação" (modal de configuração do desenho).
        if ($acao === AcaoVaga::Aprovar && !$vaga->ehDaCentral()) {
            $vaga->limitarPeriodo($ctx->configuracao->getVagas()['maxDias']);
        }
        $vaga->aplicar($acao, $motivo, $agora);
        $this->vagas->salvar($vaga, flush: true);

        return $vaga;
    }
}
