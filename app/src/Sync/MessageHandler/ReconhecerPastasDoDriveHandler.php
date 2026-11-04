<?php

declare(strict_types=1);

namespace App\Sync\MessageHandler;

use App\Entity\Tenant\Tenant;
use App\Sync\Entity\TenantDriveConexao;
use App\Sync\Exception\TenantSemDriveException;
use App\Sync\Message\ReconhecerPastasDoDrive;
use App\Sync\UseCase\ReconhecerPastasDoDriveUseCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Roda o reconhecimento do Drive no worker. No-op se o escritório sumiu ou desconectou o Drive. */
#[AsMessageHandler]
final class ReconhecerPastasDoDriveHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReconhecerPastasDoDriveUseCase $reconhecer,
    ) {
    }

    public function __invoke(ReconhecerPastasDoDrive $mensagem): void
    {
        $tenant = $this->em->find(Tenant::class, $mensagem->tenantId);
        if ($tenant === null) {
            return;
        }

        try {
            $this->reconhecer->executar($tenant);
        } catch (TenantSemDriveException) {
            return; // desconectado entre o pedido e o worker: nada a reconhecer
        } catch (\Throwable $e) {
            // A trava do envio continua e a tela mostra "tentar de novo". Gravado por SQL: o EntityManager pode ter
            // fechado com o erro. Relança para o Messenger registrar (e tentar de novo, se houver retry).
            $this->marcar($mensagem->tenantId, TenantDriveConexao::RECONHECIMENTO_FALHOU);

            throw $e;
        }
        $this->marcar($mensagem->tenantId, TenantDriveConexao::RECONHECIMENTO_CONCLUIDO);
    }

    /** Concluído vale sempre; falha só não desfaz um reconhecimento que já terminou antes. */
    private function marcar(int $tenantId, string $estado): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE sync_drive_conexao SET reconhecimento = :e WHERE tenant_id = :t AND ativo = true'
            . ($estado === TenantDriveConexao::RECONHECIMENTO_FALHOU ? " AND reconhecimento IS DISTINCT FROM 'concluido'" : ''),
            ['e' => $estado, 't' => $tenantId],
        );
    }
}
