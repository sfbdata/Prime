<?php

declare(strict_types=1);

namespace App\Dashboard\UseCase;

use App\Dashboard\Entity\AcompanhamentoDaPessoa;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Auditoria do acompanhamento de pessoa: quem criou, renovou ou encerrou, e para quem. 1 linha no
 * `audit_log`, gravada à mão (padrão de {@see RegistrarAuditoriaDoDesempenhoIndividualUseCase}). A coluna
 * `action` tem 10 caracteres, então a ação é fixa e o detalhe vai em `changes` só com o evento, o id da
 * pessoa e a data de revisão: nenhum nome e nenhum texto livre.
 *
 * Só persiste: quem chama faz o flush na MESMA transação do acompanhamento (nada pela metade).
 */
final class RegistrarAuditoriaDoAcompanhamentoUseCase
{
    public const ACAO_CRIAR   = 'acomp_cria';
    public const ACAO_RENOVAR = 'acomp_renov';
    public const ACAO_ENCERRAR = 'acomp_fim';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function criou(Tenant $tenant, User $ator, AcompanhamentoDaPessoa $a): void
    {
        $this->gravar(self::ACAO_CRIAR, 'criou', $tenant, $ator, $a);
    }

    public function renovou(Tenant $tenant, User $ator, AcompanhamentoDaPessoa $a): void
    {
        $this->gravar(self::ACAO_RENOVAR, 'renovou', $tenant, $ator, $a);
    }

    public function encerrou(Tenant $tenant, User $ator, AcompanhamentoDaPessoa $a): void
    {
        $this->gravar(self::ACAO_ENCERRAR, 'encerrou', $tenant, $ator, $a);
    }

    private function gravar(string $acao, string $evento, Tenant $tenant, User $ator, AcompanhamentoDaPessoa $a): void
    {
        $pessoaId = (int) $a->getPessoa()->getId();
        $log      = (new AuditLog())
            ->setAction($acao)
            ->setEntityClass(User::class)
            ->setEntityId((string) $pessoaId)
            ->setTenantId($tenant->getId())
            ->setActorUserId($ator->getId())
            ->setActorEmail($ator->getEmail())
            ->setChanges(['evento' => $evento, 'pessoa_id' => $pessoaId, 'revisar_em' => $a->getRevisarEm()->format('Y-m-d')]);

        $this->em->persist($log);
    }
}
