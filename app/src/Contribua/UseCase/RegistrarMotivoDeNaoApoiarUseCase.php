<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\DTO\Solicitante;
use App\Contribua\Entity\MotivoDeNaoApoiar;
use App\Contribua\Repository\MotivoDeNaoApoiarRepository;
use App\Contribua\Service\AvisoAoMaster;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Ainda não é o momento de contribuir?": um clique, anônimo. Uma resposta por escritório a cada 30
 * dias; o Master recebe "Conta Nº …: motivo", sem o nome de quem respondeu.
 */
final class RegistrarMotivoDeNaoApoiarUseCase
{
    public function __construct(
        private readonly MotivoDeNaoApoiarRepository $motivos,
        private readonly AvisoAoMaster $avisoAoMaster,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** `$motivo` é um dos quatro fixos ou, em "Outro motivo", o texto livre de quem respondeu. */
    public function executar(Solicitante $quem, string $motivo): void
    {
        if ($this->motivos->respondeuDesde($quem->tenantId(), new \DateTimeImmutable('-30 days'))) {
            return;
        }

        $resposta = new MotivoDeNaoApoiar($quem->escritorio, $motivo);
        $this->motivos->salvar($resposta, flush: true);
        $this->avisoAoMaster->avisar(
            $quem->escritorio,
            'contribua.motivo',
            sprintf('Motivo para não contribuir: Conta Nº %s: %s', $quem->numeroDaConta(), $resposta->getMotivo()),
            $this->urls->generate('contribua_index') . '#apoie',
        );
    }
}
