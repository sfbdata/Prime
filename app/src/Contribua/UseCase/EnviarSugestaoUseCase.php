<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\DTO\Solicitante;
use App\Contribua\Entity\Sugestao;
use App\Contribua\Enum\TipoDeSugestao;
use App\Contribua\Repository\SugestaoRepository;
use App\Contribua\Service\AvisoAoMaster;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Qualquer pessoa do escritório manda uma ideia, um problema ou o que falta (desenho 08). O Master é
 * avisado no sino: "Nova sugestão (Tipo)", "Nome · Escritório: texto".
 */
final class EnviarSugestaoUseCase
{
    public function __construct(
        private readonly SugestaoRepository $sugestoes,
        private readonly AvisoAoMaster $avisoAoMaster,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function executar(Solicitante $quem, TipoDeSugestao $tipo, string $texto): void
    {
        $sugestao = new Sugestao($quem->escritorio, $quem->usuario, $quem->nome(), $tipo, $texto);
        $this->sugestoes->salvar($sugestao, flush: true);

        $this->avisoAoMaster->avisar(
            $quem->escritorio,
            'contribua.sugestao',
            sprintf('Nova sugestão (%s): %s · %s: %s', $tipo->rotulo(), $quem->nome(), $quem->escritorio->getName(), mb_substr($sugestao->getTexto(), 0, 140)),
            $this->urls->generate('contribua_index') . '#sug',
        );
    }
}
