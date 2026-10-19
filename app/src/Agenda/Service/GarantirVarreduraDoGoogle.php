<?php

declare(strict_types=1);

namespace App\Agenda\Service;

use App\Agenda\Infra\GoogleAgendaApi;
use App\Agenda\Message\VarrerGoogleAgenda;
use App\Agenda\Repository\VarreduraDoGoogleRepository;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Põe a cadeia do Google Agenda para andar quando ela não está andando: ao conectar, a cada consulta de
 * lembretes (toda tela aberta, a cada 5 min) e pelo comando `app:agenda:google-varredura`. Semear duas
 * vezes não cria duas cadeias: o handler só deixa seguir quem reivindica a vez.
 */
final class GarantirVarreduraDoGoogle
{
    public function __construct(
        private readonly VarreduraDoGoogleRepository $marca,
        private readonly GoogleAgendaApi $google,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function garantir(): bool
    {
        if (!$this->google->ligado() || !$this->marca->estaParada(new \DateTimeImmutable())) {
            return false;
        }
        $this->bus->dispatch(new VarrerGoogleAgenda());

        return true;
    }
}
