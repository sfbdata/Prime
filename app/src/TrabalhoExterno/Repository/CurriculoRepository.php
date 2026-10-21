<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Repository;

use App\Entity\Auth\User;
use App\TrabalhoExterno\Entity\Curriculo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Único caminho até os currículos. A entidade não é `TenantAware` (é da plataforma): quem chama já
 * decidiu o escopo. O banco inteiro só para a Central ({@see recebidos()}, {@see porId()}); para o
 * externo, só o dele ({@see doUsuario()}). Quem decide é o `LocalizadorDeCurriculo`.
 *
 * @extends ServiceEntityRepository<Curriculo>
 */
class CurriculoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Curriculo::class);
    }

    public function salvar(Curriculo $curriculo, bool $flush = false): void
    {
        $this->getEntityManager()->persist($curriculo);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(Curriculo $curriculo): void
    {
        $this->getEntityManager()->remove($curriculo);
    }

    public function doUsuario(User $usuario): ?Curriculo
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.usuario = :usuario')
            ->setParameter('usuario', $usuario)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Só para a Central: o externo passa por {@see doUsuario()}. */
    public function porId(int $id): ?Curriculo
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * O banco de talentos da Central, do atualizado mais recentemente para o mais antigo: "Currículos
     * atualizados aparecem primeiro para os escritórios" (l.1059, 1021, 839; ordem da l.995 e 1006). A
     * coluna RECEBIDO e "Novos na semana" seguem pelo `recebidoEm`. Sem paginação, como as vagas: a tela
     * filtra, conta as abas e seleciona em lote sobre a lista inteira.
     *
     * @return list<Curriculo>
     */
    public function recebidos(): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('u')
            ->join('c.usuario', 'u')
            ->orderBy('c.atualizadoEm', 'DESC')
            ->addOrderBy('c.recebidoEm', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
