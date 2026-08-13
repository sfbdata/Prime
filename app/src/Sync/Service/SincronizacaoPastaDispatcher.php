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

    /**
     * @param bool $renomear Pede ao worker que propague o NOME da pasta ao Drive (R3). Passe
     *                       `true` só depois de comparar o nome antes/depois — ver
     *                       {@see despacharSeNomeMudou()}, que é o caminho normal.
     */
    public function despachar(Pasta $pasta, User $usuario, Tenant $tenant, bool $renomear = false): void
    {
        if ($this->conexoes->findAtivaDoTenant($tenant) === null) {
            return; // escritório sem Drive conectado — nada a sincronizar
        }

        try {
            $this->bus->dispatch(new SincronizarPastaNoDrive(
                (int) $pasta->getId(),
                (int) $tenant->getId(),
                (int) $usuario->getId(),
                $renomear,
            ));
        } catch (\Throwable $e) {
            $this->logger->error('Falha ao enfileirar sync da pasta {pasta}: {erro}', [
                'pasta' => $pasta->getId(),
                'erro'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * 6º ponto de gatilho (requisito R3): editar a pasta reflete o novo nome no Drive.
     *
     * Antes disto, mudar o número/cliente/ação no sistema NÃO mexia no Drive — a divergência só
     * era REPORTADA pelo reconciliador (linhas `[divergência]`) e ficava lá para sempre. Era a
     * origem das ~426 diferenças de nome do acervo.
     *
     * A comparação é feita AQUI, com o nome de antes capturado pelo chamador ANTES de gravar. Se
     * o nome não mudou, nada é enfileirado: nome igual não vira write no Drive (D12.3).
     *
     * @param string $nomeAntes Resultado de ReconciliadorDePasta::nomeEsperado() ANTES da edição.
     */
    public function despacharSeNomeMudou(Pasta $pasta, User $usuario, Tenant $tenant, string $nomeAntes): void
    {
        $nomeDepois = ReconciliadorDePasta::nomeEsperado(
            $pasta->getNup(),
            $pasta->getNomeCliente(),
            $pasta->getNomeAcao(),
        );

        if ($nomeAntes === $nomeDepois) {
            return;
        }

        $this->despachar($pasta, $usuario, $tenant, renomear: true);
    }
}
