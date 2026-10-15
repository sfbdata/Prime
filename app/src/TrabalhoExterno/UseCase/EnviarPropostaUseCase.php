<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\DTO\DadosDaProposta;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Entity\PropostaDemanda;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Repository\DemandaExternaRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeDemanda;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;

/**
 * Enviar a proposta comercial (v1) ou uma nova versão.
 *
 * Quem: Master, ou Equipe com "Elaborar e enviar propostas". Situação: em análise ou aguardando aceite.
 * Fluxo: as versões anteriores em aberto viram "substituída"; a nova entra como "Aguardando aceite";
 * a demanda passa a "Aguardando aceite" e o chat recebe o registro com o valor.
 * Validade não informada: hoje + 5 dias, como no protótipo.
 */
final class EnviarPropostaUseCase
{
    public function __construct(
        private readonly LocalizadorDeDemanda $localizador,
        private readonly PoliticaDaDemanda $politica,
        private readonly DemandaExternaRepository $repositorio,
    ) {
    }

    public function executar(Contexto $ctx, string $codigo, DadosDaProposta $dados): DemandaExterna
    {
        $demanda = $this->localizador->localizar($ctx, $codigo);
        if (!$this->politica->podePropor($ctx, $demanda)) {
            throw new AcaoNaoPermitida('Você não pode enviar proposta nesta demanda.');
        }

        $autor = $ctx->autor();
        $proposta = new PropostaDemanda(
            $demanda,
            $demanda->proximaVersaoDeProposta(),
            $dados->valor,
            $dados->despesas,
            $dados->prazoEstimado,
            $dados->validade ?? new \DateTimeImmutable('today +5 days'),
            $dados->pagamento,
            $dados->escopo,
            $dados->naoInclui,
            $autor,
        );
        $demanda->enviarProposta($proposta, $autor);
        $this->repositorio->salvar($demanda, flush: true);

        return $demanda;
    }
}
