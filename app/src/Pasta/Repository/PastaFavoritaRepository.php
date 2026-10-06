<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaFavorita;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Favoritos de pasta. Toda consulta é por escritório E por usuário: favorito é preferência
 * pessoal, e o de um colega não muda nada para mim.
 *
 * @extends ServiceEntityRepository<PastaFavorita>
 */
class PastaFavoritaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PastaFavorita::class);
    }

    public function buscarDoUsuario(Pasta $pasta, User $usuario, Tenant $tenant): ?PastaFavorita
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.tenant = :tenant')
            ->andWhere('f.usuario = :usuario')
            ->andWhere('f.pasta = :pasta')
            ->setParameter('tenant', $tenant)
            ->setParameter('usuario', $usuario)
            ->setParameter('pasta', $pasta)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function ehFavorita(Pasta $pasta, User $usuario, Tenant $tenant): bool
    {
        return $this->buscarDoUsuario($pasta, $usuario, $tenant) !== null;
    }

    /**
     * Ids das pastas que o usuário fixou neste escritório, como conjunto (`[id => true]`) — é o
     * formato que a listagem consulta linha a linha sem varrer array.
     *
     * @return array<int, true>
     */
    public function idsDasPastasFavoritas(User $usuario, Tenant $tenant): array
    {
        $ids = $this->createQueryBuilder('f')
            ->select('IDENTITY(f.pasta) AS pasta_id')
            ->andWhere('f.tenant = :tenant')
            ->andWhere('f.usuario = :usuario')
            ->setParameter('tenant', $tenant)
            ->setParameter('usuario', $usuario)
            ->getQuery()
            ->getSingleColumnResult();

        return array_fill_keys(array_map('intval', $ids), true);
    }

    /**
     * Fixa a pasta nos favoritos do usuário de forma IDEMPOTENTE: se a linha já existe (clique
     * duplo, duas abas), não faz nada e não dá erro.
     *
     * É SQL direto com `ON CONFLICT DO NOTHING` (PostgreSQL), e não `persist()` + `flush()`, de
     * propósito: no caminho do ORM a corrida estoura `UniqueConstraintViolationException`, e
     * depois dela o EntityManager FECHA — qualquer uso posterior na mesma requisição (o Twig, um
     * listener, o log de auditoria) quebraria. Aqui o banco decide a corrida e nada lança.
     * O UNIQUE (user_id, pasta_id) é o árbitro.
     */
    public function inserirSeAusente(Tenant $tenant, User $usuario, Pasta $pasta): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO pasta_favorita (criado_em, tenant_id, user_id, pasta_id)
             VALUES (:criado_em, :tenant, :usuario, :pasta)
             ON CONFLICT (user_id, pasta_id) DO NOTHING',
            [
                'criado_em' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'tenant'    => $tenant->getId(),
                'usuario'   => $usuario->getId(),
                'pasta'     => $pasta->getId(),
            ],
        );
    }

    public function salvar(PastaFavorita $favorita, bool $flush = false): void
    {
        $this->getEntityManager()->persist($favorita);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(PastaFavorita $favorita, bool $flush = false): void
    {
        $this->getEntityManager()->remove($favorita);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
