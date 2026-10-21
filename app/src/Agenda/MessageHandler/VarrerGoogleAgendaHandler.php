<?php

declare(strict_types=1);

namespace App\Agenda\MessageHandler;

use App\Agenda\Infra\GoogleAgendaApi;
use App\Agenda\Message\SincronizarGoogleDaPessoa;
use App\Agenda\Message\VarrerGoogleAgenda;
use App\Agenda\Repository\ConexaoGoogleAgendaRepository;
use App\Agenda\Repository\VarreduraDoGoogleRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * A cadeia do Google Agenda, no molde da do push (5b): reivindica a vez na marca do banco, pede uma
 * sincronia por conexão (cada pessoa numa mensagem: uma agenda lenta não segura as outras) e agenda a
 * próxima volta em 5 min. Mensagem sem a vez é de cadeia duplicada e morre. Sem o cliente OAuth, para.
 */
#[AsMessageHandler]
final class VarrerGoogleAgendaHandler
{
    public function __construct(
        private readonly VarreduraDoGoogleRepository $marca,
        private readonly ConexaoGoogleAgendaRepository $conexoes,
        private readonly GoogleAgendaApi $google,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function __invoke(VarrerGoogleAgenda $mensagem): void
    {
        if (!$this->google->ligado() || !$this->marca->reivindicar(new \DateTimeImmutable())) {
            return;
        }
        try {
            foreach ($this->conexoes->idsParaSincronizar() as $id) {
                $this->bus->dispatch(new SincronizarGoogleDaPessoa($id));
            }
        } finally {
            $this->bus->dispatch(new VarrerGoogleAgenda(), [new DelayStamp(VarreduraDoGoogleRepository::INTERVALO_SEGUNDOS * 1000)]);
        }
    }
}
