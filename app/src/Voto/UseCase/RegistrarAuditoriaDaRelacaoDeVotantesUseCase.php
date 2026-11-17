<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Voto\Entity\Assembleia;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Trilha da relação de votantes (spec voto-equipe-e-master-1611, seção 2): cada emissão grava "Relação de votantes
 * emitida" no `audit_log`, no molde de `RegistrarAuditoriaDoAcompanhamentoUseCase`. A coluna `action` tem 10
 * caracteres, então a ação é fixa e a frase do desenho vai em `changes`, com os ids das pautas emitidas: nenhum nome de
 * votante e nenhum texto livre.
 *
 * Só persiste: quem chama faz o flush.
 */
final class RegistrarAuditoriaDaRelacaoDeVotantesUseCase
{
    public const ACAO = 'voto_relac';
    public const EVENTO = 'Relação de votantes emitida';

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @param list<int> $pautaIds as pautas cuja relação saiu no documento */
    public function emitiu(Tenant $tenant, User $ator, Assembleia $assembleia, array $pautaIds): void
    {
        $log = (new AuditLog())
            ->setAction(self::ACAO)
            ->setEntityClass(Assembleia::class)
            ->setEntityId((string) $assembleia->getId())
            ->setTenantId($tenant->getId())
            ->setActorUserId($ator->getId())
            ->setActorEmail($ator->getEmail())
            ->setChanges(['evento' => self::EVENTO, 'assembleia_id' => $assembleia->getId(), 'pauta_ids' => $pautaIds]);

        $this->em->persist($log);
    }
}
