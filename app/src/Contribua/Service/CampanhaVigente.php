<?php

declare(strict_types=1);

namespace App\Contribua\Service;

use App\Contribua\Entity\Campanha;
use App\Contribua\Enum\EstadoDaCampanha;
use App\Contribua\Repository\CampanhaParticipacaoRepository;
use App\Contribua\Repository\CampanhaRepository;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Service\ResetInterface;

/** A campanha vigente (a de maior id), o estado dela hoje e "hoje" no fuso de São Paulo. */
// Não-final: os testes de UseCase a dublam.
class CampanhaVigente implements ResetInterface
{
    private bool $lida = false;
    private ?Campanha $vigente = null;

    public function __construct(
        private readonly CampanhaRepository $campanhas,
        private readonly CampanhaParticipacaoRepository $participacoes,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** Memorizada por requisição: a aba do topo consulta em toda tela. */
    public function campanha(): ?Campanha
    {
        if (!$this->lida) {
            $this->vigente = $this->campanhas->vigente();
            $this->lida = true;
        }

        return $this->vigente;
    }

    /** Entre requisições no mesmo processo (worker, testes), a memória é descartada. */
    public function reset(): void
    {
        $this->lida = false;
        $this->vigente = null;
    }

    public function estado(Campanha $campanha): EstadoDaCampanha
    {
        // A contagem só importa com limite de vagas: sem limite, nenhuma consulta extra.
        $inscricoes = $campanha->getVagas() > 0 ? $this->participacoes->contarInscricoes($campanha) : 0;

        return $campanha->estado($this->hoje(), $inscricoes);
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
