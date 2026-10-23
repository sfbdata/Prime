<?php

declare(strict_types=1);

namespace App\Tenant\Service;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tenant\Enum\PoderSemPrazo;
use Doctrine\ORM\EntityManagerInterface;

/**
 * O rastro da edição sem prazo (spec `edicao-sem-prazo-master-primario.md` §6): quando o Master ou o primário edita ou
 * exclui pelo poder (a regra de sempre não deixaria: fora do prazo ou conteúdo de outra pessoa), grava no `audit_log`
 * quem fez, o papel, a entidade, o id e a ação. Só ids e rótulos: o texto do conteúdo não entra (o `AuditLogSubscriber`
 * da entidade, quando ela é `Auditavel`, segue com a regra dele). Só persiste: o `flush` é do UseCase.
 */
final class AuditoriaDaEdicaoSemPrazo
{
    /** A coluna `action` é VARCHAR(10). */
    public const EDITAR = 'sp_editar';
    public const EXCLUIR = 'sp_excluir';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PoderDeEdicaoSemPrazo $poder,
    ) {
    }

    /**
     * Grava só quando foi o poder que liberou. `$autorId` é o dono do conteúdo (null quando não há).
     *
     * @param self::EDITAR|self::EXCLUIR $acao
     */
    public function registrar(string $acao, User $quem, Tenant $tenant, bool $pelaRegraDeSempre, string $entidade, ?int $id, ?int $autorId): void
    {
        $poder = $this->poder->selo($quem, $tenant, $pelaRegraDeSempre);
        if ($poder === null) {
            return;
        }
        $this->em->persist((new AuditLog())
            ->setAction($acao)
            ->setEntityClass($entidade)
            ->setEntityId($id === null ? null : (string) $id)
            ->setTenantId($tenant->getId())
            ->setActorUserId($quem->getId())
            ->setActorEmail($quem->getEmail())
            ->setChanges([
                'papel' => $poder->papelNaAuditoria(),
                'poder' => $poder->value,
                'autor_id' => $autorId,
                'alheio' => $autorId !== $quem->getId(),
            ]));
    }
}
