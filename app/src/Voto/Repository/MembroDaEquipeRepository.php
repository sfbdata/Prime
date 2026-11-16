<?php

declare(strict_types=1);

namespace App\Voto\Repository;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\MembroDaEquipe;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Toda busca filtra pelo escritório por escrito (a assembleia ou o tenant informado), sem depender do filtro da sessão.
 *
 * @extends ServiceEntityRepository<MembroDaEquipe>
 */
// Não-final: os testes de UseCase do Voto o substituem por mock.
class MembroDaEquipeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MembroDaEquipe::class);
    }

    public function salvar(MembroDaEquipe $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @param list<MembroDaEquipe> $entidades */
    public function salvarVarios(array $entidades): void
    {
        foreach ($entidades as $entidade) {
            $this->getEntityManager()->persist($entidade);
        }
        $this->getEntityManager()->flush();
    }

    public function remover(MembroDaEquipe $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** Guarda de posse: a linha pelo id, só se for da assembleia (e portanto do escritório dela). */
    public function buscarDaAssembleia(int $id, Assembleia $assembleia): ?MembroDaEquipe
    {
        return $this->findOneBy(['id' => $id, 'assembleia' => $assembleia, 'tenant' => $assembleia->getTenant()]);
    }

    /** @param list<MembroDaEquipe> $entidades */
    public function removerVarios(array $entidades): void
    {
        foreach ($entidades as $entidade) {
            $this->getEntityManager()->remove($entidade);
        }
        $this->getEntityManager()->flush();
    }

    /**
     * A equipe da assembleia, na ordem em que foi montada.
     *
     * @return list<MembroDaEquipe>
     */
    public function daAssembleia(Assembleia $assembleia): array
    {
        /** @var list<MembroDaEquipe> $linhas */
        $linhas = $this->findBy(['assembleia' => $assembleia, 'tenant' => $assembleia->getTenant()], ['id' => 'ASC']);

        return $linhas;
    }

    /** A linha da pessoa nesta assembleia, se ela já tem papel nela. */
    public function buscarDaPessoa(Assembleia $assembleia, User $pessoa): ?MembroDaEquipe
    {
        return $this->findOneBy(['assembleia' => $assembleia, 'tenant' => $assembleia->getTenant(), 'user' => $pessoa]);
    }

    /**
     * As equipes de um lote de assembleias do escritório, numa consulta só: uma linha por membro, com o id da
     * assembleia, o id do usuário e o papel. O usuário só vale com vínculo ATIVO no escritório (LEFT JOIN em
     * `UserTenant`): quem perdeu o vínculo volta como `user = null`, isto é, linha sem conta, que não dá acesso. Para as
     * listas, a barreira e os KPIs (sem N+1).
     *
     * @param list<int> $assembleiaIds
     *
     * @return list<array{assembleia: int, user: int|null, papel: string}>
     */
    public function linhasDoLote(Tenant $tenant, array $assembleiaIds): array
    {
        if ([] === $assembleiaIds) {
            return [];
        }
        /** @var list<array{assembleia: int|string, user: int|string|null, papel: string|\BackedEnum, vinculo: int|string|null}> $linhas */
        $linhas = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.assembleia) AS assembleia', 'IDENTITY(m.user) AS user', 'm.papel AS papel', 'ut.id AS vinculo')
            ->leftJoin(UserTenant::class, 'ut', 'WITH', 'ut.user = m.user AND ut.tenant = m.tenant AND ut.isActive = true')
            ->andWhere('m.tenant = :tenant')
            ->andWhere('m.assembleia IN (:ids)')
            ->setParameter('tenant', $tenant)
            ->setParameter('ids', $assembleiaIds)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $l): array => [
            'assembleia' => (int) $l['assembleia'],
            'user' => null === $l['user'] || null === $l['vinculo'] ? null : (int) $l['user'],
            'papel' => $l['papel'] instanceof \BackedEnum ? (string) $l['papel']->value : (string) $l['papel'],
        ], $linhas);
    }

    /**
     * A equipe de todas as assembleias do escritório, numa consulta só, na ordem em que foi montada (a tela Perfis).
     *
     * @return list<MembroDaEquipe>
     */
    public function doEscritorio(Tenant $tenant): array
    {
        /** @var list<MembroDaEquipe> $linhas */
        $linhas = $this->findBy(['tenant' => $tenant], ['id' => 'ASC']);

        return $linhas;
    }
}
