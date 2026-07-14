<?php

declare(strict_types=1);

namespace App\Sync\MessageHandler;

use App\Entity\Tenant\Tenant;
use App\Sync\Exception\TenantSemDriveException;
use App\Sync\Message\SincronizarPastaNoDrive;
use App\Sync\Service\GoogleDriveClientFactoryInterface;
use App\Sync\Service\ReconciliadorDePasta;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler da Fase 2 (roda no worker): sincroniza SÓ a pasta do evento, em segundos, reusando o
 * mesmo motor por-pasta do cron ({@see ReconciliadorDePasta}). Resolve o client DAQUELE tenant pela
 * fábrica. No-op silencioso se o tenant não tem Drive conectado (nada a sincronizar). Erros de item
 * são logados (o cron converge o que escapar); só `fatal` (EM fechado) re-lança para o Messenger
 * fazer retry.
 */
#[AsMessageHandler]
final class SincronizarPastaNoDriveHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GoogleDriveClientFactoryInterface $clientFactory,
        private readonly ReconciliadorDePasta $reconciliador,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SincronizarPastaNoDrive $mensagem): void
    {
        $tenant = $this->em->find(Tenant::class, $mensagem->tenantId);
        if ($tenant === null) {
            return; // tenant removido — nada a fazer
        }

        try {
            $drive = $this->clientFactory->paraTenant($tenant);
        } catch (TenantSemDriveException) {
            return; // escritório sem Drive conectado — no-op
        }

        $resultado = $this->reconciliador->sincronizarPasta($mensagem->pastaId, $drive->rootFolderId, $drive->client);

        foreach ($resultado->mensagens as $msg) {
            $this->logger->warning('[sync pasta {pasta}] {msg}', ['pasta' => $mensagem->pastaId, 'msg' => $msg]);
        }

        if ($resultado->fatal) {
            // EM fechou (erro grave) — re-lança para o Messenger reprocessar (retry).
            throw new \RuntimeException(sprintf('Sync da pasta %d abortou (EntityManager fechado).', $mensagem->pastaId));
        }
    }
}
