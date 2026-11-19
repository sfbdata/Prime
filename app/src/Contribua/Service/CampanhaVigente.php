<?php

declare(strict_types=1);

namespace App\Contribua\Service;

use App\Contribua\Entity\Campanha;
use App\Contribua\Enum\EstadoDaCampanha;
use App\Contribua\Repository\CampanhaParticipacaoRepository;
use App\Contribua\Repository\CampanhaRepository;
use Psr\Clock\ClockInterface;

/** A campanha vigente (a de maior id), o estado dela hoje e "hoje" no fuso de São Paulo. */
// Não-final: os testes de UseCase a dublam.
class CampanhaVigente
{
    public function __construct(
        private readonly CampanhaRepository $campanhas,
        private readonly CampanhaParticipacaoRepository $participacoes,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function campanha(): ?Campanha
    {
        return $this->campanhas->vigente();
    }

    public function estado(Campanha $campanha): EstadoDaCampanha
    {
        return $campanha->estado($this->hoje(), $this->participacoes->contarInscricoes($campanha));
    }

    public function hoje(): \DateTimeImmutable
    {
        return FormatoDaCampanha::hoje($this->relogio);
    }

    public function agora(): \DateTimeImmutable
    {
        return $this->relogio->now();
    }
}
