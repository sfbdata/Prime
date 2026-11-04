<?php

declare(strict_types=1);

namespace App\Contribua\EventListener;

use App\Contribua\Event\EspacoPassouDoContratado;
use App\Contribua\Event\UsoDoEspacoSubiu;
use App\Contribua\Repository\ConsumoDeArmazenamentoRepository;
use App\Contribua\UseCase\AmpliarEspacoAutomaticamenteUseCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Liga a ampliação de GB automática aos eventos que saem depois do commit de cada envio: `EspacoPassouDoContratado`
 * (o envio entrou pela folga de uma dobra, com o bloqueio ligado; o uso é medido de novo) e `UsoDoEspacoSubiu` (com o
 * bloqueio desligado nada é recusado, e é por ele que o uso acima do contratado aparece). Prioridade baixa: roda depois
 * dos avisos de espaço do mesmo evento. Nunca derruba o envio, que já foi gravado: qualquer erro vai ao log só com o
 * id do escritório.
 */
final class AmpliacaoAutomaticaListener
{
    public function __construct(
        private readonly AmpliarEspacoAutomaticamenteUseCase $ampliar,
        private readonly ConsumoDeArmazenamentoRepository $consumo,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsEventListener(priority: -100)]
    public function aoPassarDoContratado(EspacoPassouDoContratado $evento): void
    {
        $this->ampliarSemDerrubar($evento->tenantId, fn (): string => $this->ampliar->executar($evento->tenantId, $this->consumo->bytesDoEscritorio($evento->tenantId), true));
    }

    #[AsEventListener(priority: -100)]
    public function aoSubirOUso(UsoDoEspacoSubiu $evento): void
    {
        $this->ampliarSemDerrubar($evento->tenantId, fn (): string => $this->ampliar->executar($evento->tenantId, $evento->retrato->bytesUsados, $evento->cruzou(100)));
    }

    /** @param \Closure(): string $ampliacao */
    private function ampliarSemDerrubar(int $tenantId, \Closure $ampliacao): void
    {
        try {
            $ampliacao();
        } catch (\Throwable $erro) {
            $this->logger->error('Contribua: a ampliação automática falhou', ['tenant' => $tenantId, 'erro' => $erro->getMessage()]);
        }
    }
}
