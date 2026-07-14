<?php

declare(strict_types=1);

namespace App\Sync\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Sync\Message\SincronizarPastaNoDrive;
use App\Sync\Repository\TenantDriveConexaoRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Gatilho da Fase 2 (camada de controller): após criar pasta / anexar documento, enfileira a
 * sincronização daquela pasta no Drive — em segundos, via worker. Enfileira SÓ se o escritório tem
 * Drive conectado (senão é no-op). O dispatch NUNCA quebra a ação do usuário: qualquer falha ao
 * enfileirar é logada e engolida (o cron converge o que escapar).
 *
 * Fica na camada de controller (não no UseCase) de propósito: (1) o reconcile chama os mesmos
 * UseCases e um dispatch lá dispararia durante a própria reconciliação (duplo-disparo); (2) alguns
 * uploads (aba Documentos / financeiro) persistem inline no controller, sem UseCase.
 */
final class SincronizacaoPastaDispatcher
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly TenantDriveConexaoRepository $conexoes,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function despachar(Pasta $pasta, User $usuario, Tenant $tenant): void
    {
        if ($this->conexoes->findAtivaDoTenant($tenant) === null) {
            return; // escritório sem Drive conectado — nada a sincronizar
        }

        try {
            $this->bus->dispatch(new SincronizarPastaNoDrive(
                (int) $pasta->getId(),
                (int) $tenant->getId(),
                (int) $usuario->getId(),
            ));
        } catch (\Throwable $e) {
            $this->logger->error('Falha ao enfileirar sync da pasta {pasta}: {erro}', [
                'pasta' => $pasta->getId(),
                'erro'  => $e->getMessage(),
            ]);
        }
    }
}
