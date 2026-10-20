<?php

declare(strict_types=1);

namespace App\Agenda\MessageHandler;

use App\Agenda\Message\SincronizarGoogleDaPessoa;
use App\Agenda\UseCase\SincronizarGoogleAgendaUseCase;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Sincroniza uma pessoa. Se outra sincronia dela está rodando (trava), tenta de novo em 30 s, até 10 vezes:
 * a mudança feita na tela não espera a volta de 5 min (a trava presa se solta sozinha em 5 min).
 */
#[AsMessageHandler]
final class SincronizarGoogleDaPessoaHandler
{
    private const ESPERA_MS = 30000;
    private const TENTATIVAS = 10;

    public function __construct(
        private readonly SincronizarGoogleAgendaUseCase $sincronizar,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function __invoke(SincronizarGoogleDaPessoa $mensagem): void
    {
        $feito = $this->sincronizar->executar($mensagem->conexaoId, new \DateTimeImmutable());
        if (null === $feito && $mensagem->tentativa < self::TENTATIVAS) {
            $this->bus->dispatch(new SincronizarGoogleDaPessoa($mensagem->conexaoId, $mensagem->tentativa + 1), [new DelayStamp(self::ESPERA_MS)]);
        }
    }
}
