<?php

declare(strict_types=1);

namespace App\Dashboard\UseCase;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Auditoria do desempenho individual (dc L2486 `eqAuditar('visualizou Desempenho individual · nome')`):
 * quem olhou a análise de quem. 1 linha no `audit_log`, gravada à mão (não há entidade mudando, o
 * `AuditLogSubscriber` não a veria). A coluna `action` tem 10 caracteres, então a ação é fixa
 * (`ia_pessoa` ao visualizar, `ia_marca` ao marcar) e o detalhe vai em `changes` só com o evento e o
 * id da pessoa: nenhum texto livre e nenhum nome.
 */
final class RegistrarAuditoriaDoDesempenhoIndividualUseCase
{
    public const ACAO_VISUALIZAR = 'ia_pessoa';
    public const ACAO_MARCAR     = 'ia_marca';

    public const EVENTO_VISUALIZOU = 'visualizou';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function visualizou(Tenant $tenant, User $ator, int $pessoaId): void
    {
        $this->gravar(self::ACAO_VISUALIZAR, $tenant, $ator, $pessoaId, self::EVENTO_VISUALIZOU);
    }

    /** @param string $evento a ação aceita (MarcasDasPessoas::acoes()) */
    public function marcou(Tenant $tenant, User $ator, int $pessoaId, string $evento): void
    {
        $this->gravar(self::ACAO_MARCAR, $tenant, $ator, $pessoaId, $evento);
    }

    private function gravar(string $acao, Tenant $tenant, User $ator, int $pessoaId, string $evento): void
    {
        $log = (new AuditLog())
            ->setAction($acao)
            ->setEntityClass(User::class)
            ->setEntityId((string) $pessoaId)
            ->setTenantId($tenant->getId())
            ->setActorUserId($ator->getId())
            ->setActorEmail($ator->getEmail())
            ->setChanges(['evento' => $evento, 'pessoa_id' => $pessoaId]);

        $this->em->persist($log);
        $this->em->flush();
    }
}
