<?php

declare(strict_types=1);

namespace App\Sync\MessageHandler;

use App\Entity\Tenant\Tenant;
use App\Sync\Exception\TenantSemDriveException;
use App\Sync\Enum\ModoSincronizacao;
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

        // Defesa em profundidade multi-tenant: a mensagem é a fronteira de confiança do worker.
        // Nunca sincronizar uma pasta de OUTRO tenant contra o Drive do tenant da mensagem.
        $pastaTenantId = $this->em->getConnection()->fetchOne(
            'SELECT tenant_id FROM pasta WHERE id = :id',
            ['id' => $mensagem->pastaId],
        );
        if ($pastaTenantId === false) {
            return; // pasta removida — nada a fazer
        }
        if ((int) $pastaTenantId !== $mensagem->tenantId) {
            $this->logger->warning('Sync ignorado: pasta {pasta} pertence ao tenant {real}, não ao {msg} da mensagem.', [
                'pasta' => $mensagem->pastaId,
                'real'  => (int) $pastaTenantId,
                'msg'   => $mensagem->tenantId,
            ]);

            return;
        }

        try {
            $drive = $this->clientFactory->paraTenant($tenant);
        } catch (TenantSemDriveException) {
            return; // escritório sem Drive conectado — no-op
        }

        // Sempre `Enviar`: o worker é caminho AUTOMÁTICO e, por decisão do dono (R2), automático
        // nunca importa do Drive. Antes o handler herdava o comportamento bidirecional do motor.
        $resultado = $this->reconciliador->sincronizarPasta(
            $mensagem->pastaId,
            $drive->rootFolderId,
            $drive->client,
            dryRun: false,
            modo: ModoSincronizacao::Enviar,
        );

        // R3: a propagação do nome vem depois do envio, porque `garantirFolderDaPasta` pode ter
        // acabado de criar a pasta no Drive — e aí ela já nasce com o nome certo, tornando a
        // renomeação um no-op barato em vez de um erro por folder inexistente.
        //
        // O `?? false` NÃO é paranoia: o transport é `doctrine://` com o PhpSerializer nativo
        // (config/packages/messenger.yaml, sem `serializer:`), então o payload gravado na fila
        // é um `serialize()` do objeto. Toda mensagem enfileirada ANTES deste deploy foi
        // serializada sem a propriedade `renomear`; ao desserializar, uma propriedade tipada
        // ausente fica NÃO INICIALIZADA — e lê-la direto lançaria "must not be accessed before
        // initialization", derrubando o worker em 3 retries até a fila `failed`. `??` usa
        // semântica de isset(), que é o único acesso seguro a propriedade tipada não
        // inicializada. Worker em restart-loop por variável faltando já aconteceu nesta casa.
        if (($mensagem->renomear ?? false) === true) {
            $this->reconciliador->renomearNoDrive($mensagem->pastaId, $drive->client, false, $resultado);
        }

        foreach ($resultado->mensagens as $msg) {
            $this->logger->warning('[sync pasta {pasta}] {msg}', ['pasta' => $mensagem->pastaId, 'msg' => $msg]);
        }

        if ($resultado->fatal) {
            // EM fechou (erro grave) — re-lança para o Messenger reprocessar (retry).
            throw new \RuntimeException(sprintf('Sync da pasta %d abortou (EntityManager fechado).', $mensagem->pastaId));
        }
    }
}
