<?php

declare(strict_types=1);

namespace App\Contribua\Service;

use App\Contribua\Entity\Campanha;
use App\Contribua\Enum\EstadoDaCampanha;
use App\Contribua\Repository\CampanhaParticipacaoRepository;
use App\Contribua\Repository\CampanhaRepository;
use App\Entity\Tenant\Tenant;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Service\ResetInterface;

/** A campanha vigente (a de maior id), o estado dela hoje e "hoje" no fuso de São Paulo. */
// Não-final: os testes de UseCase a dublam.
class CampanhaVigente implements ResetInterface
{
    /** A campanha só vale para escritório criado há até este número de dias (pedido do Farlei, 19/11/2026). */
    public const DIAS_DA_CONTA_NOVA = 5;

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

    /** Escritório criado há até 5 dias (contados em horas, de agora para trás): só ele vê a campanha e se inscreve. */
    public function contaNova(Tenant $escritorio): bool
    {
        $criadoEm = $escritorio->getCreatedAt();

        return $criadoEm !== null && $criadoEm >= $this->agora()->modify('-' . self::DIAS_DA_CONTA_NOVA . ' days');
    }
}
