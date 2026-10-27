<?php

declare(strict_types=1);

namespace App\Tarefa\Service;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Auditoria da redistribuição, gravada à mão no `audit_log` (a coluna `action` tem 10 caracteres): `meta_redis` por
 * meta movida (entity Tarefa, `changes` só com os ids de origem e destino) e `ia_redis` por confirmação (entity User
 * da origem, `changes` só com o id da pessoa e o total). Sem nomes. Só persiste: o flush é da transação do UseCase.
 *
 * O `AuditLogSubscriber` já registra um `update` da Tarefa com a troca em `responsaveis` (ManyToMany); estas linhas
 * não o duplicam: dizem QUEM redistribuiu de quem para quem, e a operação como um todo.
 */
class AuditoriaDaRedistribuicao
{
    public const ACAO_META     = 'meta_redis';
    public const ACAO_OPERACAO = 'ia_redis';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function meta(Tenant $tenant, User $ator, Tarefa $meta, int $origemId, int $destinoId): void
    {
        $this->gravar(self::ACAO_META, Tarefa::class, (int) $meta->getId(), $tenant, $ator, ['origem_id' => $origemId, 'destino_id' => $destinoId]);
    }

    public function operacao(Tenant $tenant, User $ator, int $pessoaId, int $total): void
    {
        $this->gravar(self::ACAO_OPERACAO, User::class, $pessoaId, $tenant, $ator, ['pessoa_id' => $pessoaId, 'total' => $total]);
    }

    /** @param array<string, int> $changes */
    private function gravar(string $acao, string $classe, int $entidadeId, Tenant $tenant, User $ator, array $changes): void
    {
        $this->em->persist((new AuditLog())
            ->setAction($acao)
            ->setEntityClass($classe)
            ->setEntityId((string) $entidadeId)
            ->setTenantId($tenant->getId())
            ->setActorUserId($ator->getId())
            ->setActorEmail($ator->getEmail())
            ->setChanges($changes));
    }
}
