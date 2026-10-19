<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Djen\Service\AvisoDePublicacoesNovasInterface;
use App\Entity\Tenant\Tenant;
use App\Pasta\UseCase\AvisarQuandoChegarNoPushUseCase;
use App\Pasta\UseCase\EnviarMovimentacoesAoClienteUseCase;
use Psr\Log\LoggerInterface;

/**
 * Liga a captação do DJEN ao Push: o envio automático ao cliente (novas movimentações e audiências e
 * intimações, P4d) e o "Avisar quando chegar" (equipe, P4b), NESSA ordem, uma vez por execução.
 * Cumpre o contrato de nunca lançar: um erro inesperado (banco, template) vai ao log com a classe e o
 * escritório, e a captação segue. Os dois são independentes: a falha de um não impede o outro.
 */
final class AvisoDoPushNaCaptacao implements AvisoDePublicacoesNovasInterface
{
    public function __construct(
        private readonly AvisarQuandoChegarNoPushUseCase $avisar,
        private readonly EnviarMovimentacoesAoClienteUseCase $enviarAoCliente,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function avisar(Tenant $tenant, array $djenIdsNovos): void
    {
        if ($djenIdsNovos === []) {
            return;
        }

        // O cliente primeiro: uma falha no aviso à equipe que feche o EntityManager não pode deixar o
        // cliente sem o e-mail (revisão do P4d).
        try {
            $this->enviarAoCliente->executar($tenant, $djenIdsNovos);
        } catch (\Throwable $e) {
            $this->logger->error('Envio automático ao cliente: falha na captação do tenant {tenant} ({classe}).', [
                'tenant' => $tenant->getId(), 'classe' => $e::class,
            ]);
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
