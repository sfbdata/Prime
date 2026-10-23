<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Djen\Service\AvisoDePublicacoesNovasInterface;
use App\Entity\Tenant\Tenant;
use App\Pasta\UseCase\AvisarQuandoChegarNoPushUseCase;
use App\Pasta\UseCase\EnviarMovimentacoesAoClienteUseCase;
use App\Pasta\UseCase\SincronizarPrazosDoPushUseCase;
use Psr\Log\LoggerInterface;

/**
 * Liga a captação do DJEN ao Push: o envio automático ao cliente (novas movimentações e audiências e
 * intimações, P4d), o "Avisar quando chegar" (equipe, P4b) e os prazos calculados das publicações
 * (docs/specs/prazo-do-push.md §3), NESSA ordem, uma vez por execução.
 * Cumpre o contrato de nunca lançar: um erro inesperado (banco, template) vai ao log com a classe e o
 * escritório, e a captação segue. Os três são independentes: a falha de um não impede os outros (o prazo
 * que não for gravado aqui volta na próxima publicação da pasta ou no `app:pastas:prazos-do-push`).
 */
final class AvisoDoPushNaCaptacao implements AvisoDePublicacoesNovasInterface
{
    public function __construct(
        private readonly AvisarQuandoChegarNoPushUseCase $avisar,
        private readonly EnviarMovimentacoesAoClienteUseCase $enviarAoCliente,
        private readonly LoggerInterface $logger,
        private readonly SincronizarPrazosDoPushUseCase $prazos,
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

        try {
            $this->prazos->executar($tenant, $djenIdsNovos);
        } catch (\Throwable $e) {
            $this->logger->error('Prazos do Push: falha ao calcular os prazos das pastas do tenant {tenant} ({classe}).', [
                'tenant' => $tenant->getId(), 'classe' => $e::class,
            ]);
        }
    }
}
