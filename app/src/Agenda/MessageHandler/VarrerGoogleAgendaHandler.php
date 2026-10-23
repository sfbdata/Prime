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

/**
 * Uma volta da sincronia do Google Agenda, no molde da do push: quem a dispara, a cada 5 min, é o schedule `cadeias`
 * ({@see \App\Shared\Scheduler\AgendaDasCadeias}); o handler não se reagenda. Reivindica a vez na marca do banco e
 * pede uma sincronia por conexão (cada pessoa numa mensagem: uma agenda lenta não segura as outras). Uma volta a mais
 * dentro da janela não pede de novo. Sem o cliente OAuth, não faz nada.
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
        foreach ($this->conexoes->idsParaSincronizar() as $id) {
            $this->bus->dispatch(new SincronizarGoogleDaPessoa($id));
        }
    }
}
