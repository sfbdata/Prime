<?php

declare(strict_types=1);

namespace App\Comercial\Repository;

use App\Comercial\Entity\ModeloDeCampanha;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Os modelos próprios do Master (spec `comercial-e-emails.md` §8.2). Plataforma, sem escritório: só o Master chega
 * aqui (`ROLE_SUPER_ADMIN` no controller), então nenhuma consulta filtra por tenant.
 *
 * Gravar e excluir levam a linha manual do audit_log na mesma transação (R6): `modelo_new`, `modelo_upd` ou
 * `modelo_del`, com `changes` só `{nome, modo}` (nunca o conteúdo).
 *
 * @extends ServiceEntityRepository<ModeloDeCampanha>
 */
class ModeloDeCampanhaRepository extends ServiceEntityRepository
{
    public const NOVO = 'modelo_new';
    public const ALTERADO = 'modelo_upd';
    public const EXCLUIDO = 'modelo_del';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ModeloDeCampanha::class);
    }

    /** @return list<ModeloDeCampanha> do mais antigo ao mais novo (a ordem em que o dc acrescenta à grade) */
    public function todos(): array
    {
        return $this->findBy([], ['id' => 'ASC']);
    }

    public function porId(int $id): ?ModeloDeCampanha
    {
        return $this->find($id);
    }

    /** Grava o modelo e a linha da trilha na mesma transação. */
    public function salvar(ModeloDeCampanha $modelo, string $acao, User $por): void
    {
        $em = $this->getEntityManager();
        $em->wrapInTransaction(static function () use ($em, $modelo, $acao, $por): void {
            $em->persist($modelo);
            $em->flush();
            $em->persist(self::trilha($modelo, (string) $modelo->getId(), $acao, $por));
            $em->flush();
        });
    }

    public function excluir(ModeloDeCampanha $modelo, User $por): void
    {
        $em = $this->getEntityManager();
        $id = (string) $modelo->getId();
        $em->wrapInTransaction(static function () use ($em, $modelo, $id, $por): void {
            $em->remove($modelo);
            $em->persist(self::trilha($modelo, $id, self::EXCLUIDO, $por));
            $em->flush();
        });
    }

    private static function trilha(ModeloDeCampanha $modelo, string $id, string $acao, User $por): AuditLog
    {
        return (new AuditLog())
            ->setAction($acao)
            ->setEntityClass(ModeloDeCampanha::class)
            ->setEntityId($id)
            ->setActorUserId($por->getId())
            ->setActorEmail($por->getEmail())
            ->setChanges(['nome' => $modelo->getNome(), 'modo' => $modelo->getModo()]);
    }
}
