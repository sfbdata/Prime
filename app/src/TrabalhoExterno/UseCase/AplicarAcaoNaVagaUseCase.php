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
 * Aprovar e publicar, não aprovar, pausar inscrições, reabrir, ofertar novamente e encerrar (desenho
 * 06 l.979–983). Só a Central. Fluxo: localiza no escopo (404); confere a política (403); a entidade
 * grava o vencimento que estava só calculado e confere a situação (409). A aprovação aplica o teto de
 * dias e, se o Master não escolheu o tipo, o tempo padrão da oferta da configuração.
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

        $cfg = $ctx->configuracao->getVagas();
        $vaga->aplicar($acao, $motivo, $ctx->autor()->nome, $cfg['dur'], $cfg['maxDias'], $agora);
        $this->vagas->salvar($vaga, flush: true);

        return $vaga;
    }
}
