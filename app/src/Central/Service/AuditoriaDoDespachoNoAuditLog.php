<?php

declare(strict_types=1);

namespace App\Central\Service;

use App\Central\Contract\AuditoriaDoDespacho;
use App\Central\Enum\PrioridadeDoDespacho;
use App\Central\Enum\TipoDeItemEncaminhado;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use Doctrine\ORM\EntityManagerInterface;

/**
 * O `audit_log` do despacho, gravado à mão como o da exportação da análise (`RegistrarExportacaoDaAnaliseUseCase`): a
 * mensagem da Central não é `Auditavel`, então o `AuditLogSubscriber` não veria nada. `changes` leva só ids e códigos.
 * Só persiste: o `flush` é do UseCase, na transação do despacho.
 */
final class AuditoriaDoDespachoNoAuditLog implements AuditoriaDoDespacho
{
    /** A ação gravada (a coluna `action` é VARCHAR(10)). */
    public const ACAO = 'despacho';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function registrar(
        User $autor,
        Tenant $tenant,
        Pasta $pasta,
        TipoDeItemEncaminhado $tipo,
        ?int $itemId,
        int $conversaId,
        PrioridadeDoDespacho $prioridade,
    ): void {
        $log = (new AuditLog())
            ->setAction(self::ACAO)
            ->setEntityClass(Pasta::class)
            ->setEntityId((string) $pasta->getId())
            ->setTenantId($tenant->getId())
            ->setActorUserId($autor->getId())
            ->setActorEmail($autor->getEmail())
            ->setChanges([
                'tipo' => $tipo->value,
                'item_id' => $itemId,
                'conversa_id' => $conversaId,
                'prioridade' => $prioridade->value,
            ]);

        $this->entityManager->persist($log);
    }
}
