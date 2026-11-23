<?php

declare(strict_types=1);

namespace App\Backup\UseCase;

use App\Backup\Armazenamento\ChavesDeBackup;
use App\Backup\DTO\BackupParaBaixar;
use App\Backup\Entity\PedidoDeBackup;
use App\Backup\Exception\BackupIndisponivel;
use App\Backup\Repository\PedidoDeBackupRepository;
use App\Backup\Service\DonoDoEscritorio;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Autoriza o download do ZIP de um backup (spec backup-e-exportacao-de-dados, seção 2, regras 4, 5 e 7). O pedido é
 * buscado pelo escritório ATUAL (`doEscritorio`) e só sai para o dono, pronto e dentro da validade; qualquer outra
 * situação, inclusive o arquivo ter sumido do armazenamento, é {@see BackupIndisponivel} (404, sem dizer o motivo).
 *
 * O download não muda a entidade, então o `AuditLogSubscriber` não o veria: é gravado à mão no `audit_log` com a ação
 * {@see ACAO_AUDITORIA} (`audit_log.action` é VARCHAR(10)), depois de a resposta de entrega ser obtida ({@see registrarDownload()}).
 */
final class BaixarBackupUseCase
{
    /** A ação gravada no `audit_log` (coluna de 10 caracteres). */
    public const ACAO_AUDITORIA = 'backup_dl';

    public function __construct(
        private readonly PedidoDeBackupRepository $pedidos,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly DonoDoEscritorio $dono,
    ) {
    }

    /**
     * @throws AccessDeniedException quem não é o dono do escritório (403, antes de qualquer outra checagem)
     * @throws BackupIndisponivel
     */
    public function executar(int $pedidoId, User $usuario, Tenant $tenant): BackupParaBaixar
    {
        if (!$this->dono->ehDono($usuario, $tenant)) {
            throw new AccessDeniedException('Só o dono do escritório pode baixar o backup.');
        }

        $agora = \DateTimeImmutable::createFromInterface($this->clock->now());
        $pedido = $this->pedidos->doEscritorio($pedidoId, (int) $tenant->getId());
        if (null === $pedido || !$pedido->podeSerBaixadoPor($usuario, $tenant, $agora)) {
            throw BackupIndisponivel::porQualquerMotivo();
        }

        $chave = ChavesDeBackup::zip($pedido);
        if (!$this->armazenamento->existe($chave)) {
            throw BackupIndisponivel::porQualquerMotivo();
        }

        return new BackupParaBaixar(
            $chave,
            'backup-bluejus-' . ($pedido->getConcluidoEm() ?? $pedido->getPedidoEm())->format('Y-m-d') . '.zip',
            (int) $pedido->getId(),
            $pedido->getTamanhoBytes(),
        );
    }

    /**
     * Grava o `backup_dl` no `audit_log`. O controller chama DEPOIS de obter a resposta de entrega sem exceção: se o
     * armazenamento falha (404/503), não sobra auditoria de um download que não aconteceu.
     */
    public function registrarDownload(BackupParaBaixar $backup, User $usuario, Tenant $tenant): void
    {
        $log = (new AuditLog())
            ->setAction(self::ACAO_AUDITORIA)
            ->setEntityClass(PedidoDeBackup::class)
            ->setEntityId((string) $backup->pedidoId)
            ->setTenantId($tenant->getId())
            ->setActorUserId($usuario->getId())
            ->setActorEmail($usuario->getEmail())
            ->setChanges(['bytes' => $backup->tamanhoBytes]);

        $this->em->persist($log);
        $this->em->flush();
    }
}
