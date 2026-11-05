<?php

declare(strict_types=1);

namespace App\Sync\UseCase;

use App\Entity\Tenant\Tenant;
use App\Sync\Message\ReconhecerPastasDoDrive;
use Doctrine\ORM\EntityManagerInterface;
use App\Sync\Repository\TenantDriveConexaoRepository;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Botão "Reconhecer de novo" da tela do Drive: pede ao worker uma nova rodada do reconhecimento
 * ({@see ReconhecerPastasDoDriveUseCase}). Só com a conexão do escritório pronta (conta e pasta-raiz).
 */
final class PedirReconhecimentoDoDriveUseCase
{
    public function __construct(
        private readonly TenantDriveConexaoRepository $conexoes,
        private readonly MessageBusInterface $bus,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(Tenant $tenant): void
    {
        $conexao = $this->conexoes->findAtivaDoTenant($tenant);
        if ($conexao === null) {
            throw new \DomainException('Conecte a conta do Google e informe a pasta-raiz antes de reconhecer as pastas.');
        }
        $conexao->pedirReconhecimentoDeNovo();
        $this->em->flush();

        $this->bus->dispatch(new ReconhecerPastasDoDrive((int) $tenant->getId()));
    }
}
