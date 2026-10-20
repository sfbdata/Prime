<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Djen\Service\AvisoDePublicacoesNovasInterface;
use App\Entity\Tenant\Tenant;
use App\Pasta\UseCase\AvisarQuandoChegarNoPushUseCase;
use Psr\Log\LoggerInterface;

/**
 * Liga a captação do DJEN ao "Avisar quando chegar" do Push. Cumpre o contrato de nunca lançar: um
 * erro inesperado (banco, template) vai ao log com a classe e o escritório, e a captação segue.
 */
final class AvisoDoPushNaCaptacao implements AvisoDePublicacoesNovasInterface
{
    public function __construct(
        private readonly AvisarQuandoChegarNoPushUseCase $avisar,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function avisar(Tenant $tenant, array $djenIdsNovos): void
    {
        if ($djenIdsNovos === []) {
            return;
        }

        try {
            $this->avisar->executar($tenant, $djenIdsNovos);
        } catch (\Throwable $e) {
            $this->logger->error('Aviso do Push: falha ao avisar as pastas do tenant {tenant} ({classe}).', [
                'tenant' => $tenant->getId(), 'classe' => $e::class,
            ]);
        }
    }
}
