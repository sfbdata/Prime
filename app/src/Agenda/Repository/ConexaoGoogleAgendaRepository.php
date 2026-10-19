<?php

declare(strict_types=1);

namespace App\Agenda\Repository;

use App\Agenda\Entity\ConexaoGoogleAgenda;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ConexaoGoogleAgenda> */
class ConexaoGoogleAgendaRepository extends ServiceEntityRepository
{
    /** Uma sincronia presa (worker morto no meio) libera a trava depois disso. */
    private const TRAVA_SEGUNDOS = 300;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConexaoGoogleAgenda::class);
    }

    /** Filtra pelo escritório à mão: a sincronia roda no worker, onde o filtro de escritório não age. */
    public function daPessoa(User $usuario, Tenant $tenant): ?ConexaoGoogleAgenda
    {
        return $this->findOneBy(['usuario' => $usuario, 'tenant' => $tenant]);
    }

    /**
     * Conexões de pessoas e escritórios ativos, que ainda têm autorização.
     *
     * @return list<int>
     */
    public function idsParaSincronizar(): array
    {
        return array_map('intval', $this->createQueryBuilder('c')
            ->select('c.id')
            ->join('c.usuario', 'u')
            ->join('c.tenant', 't')
            ->andWhere('u.isActive = true')
            ->andWhere('t.isActive = true')
            ->andWhere('c.erro IS NULL OR c.erro <> :recusada')
            ->setParameter('recusada', ConexaoGoogleAgenda::ERRO_AUTORIZACAO)
            ->orderBy('c.id', 'ASC')
            ->getQuery()
            ->getSingleColumnResult());
    }

    /** A conta já está ligada em outro escritório? Em SQL: o filtro de escritório esconderia a outra conexão. */
    public function contaEmOutroEscritorio(string $conta, Tenant $tenant): bool
    {
        return false !== $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT 1 FROM agenda_google_conexao WHERE LOWER(conta) = LOWER(:conta) AND tenant_id <> :tenant',
            ['conta' => $conta, 'tenant' => $tenant->getId()],
        );
    }

    /** Trava atômica: duas sincronias da mesma pessoa ao mesmo tempo duplicariam envios. */
    public function ocupar(int $id, \DateTimeImmutable $agora): bool
    {
        return 1 === $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE agenda_google_conexao SET ocupada_desde = :agora WHERE id = :id AND (ocupada_desde IS NULL OR ocupada_desde < :limite)',
            ['agora' => $agora->format('Y-m-d H:i:s'), 'id' => $id, 'limite' => $agora->modify('-' . self::TRAVA_SEGUNDOS . ' seconds')->format('Y-m-d H:i:s')],
        );
    }

    public function liberar(int $id): void
    {
        $this->getEntityManager()->getConnection()->executeStatement('UPDATE agenda_google_conexao SET ocupada_desde = NULL WHERE id = :id', ['id' => $id]);
    }

    public function salvar(ConexaoGoogleAgenda $conexao): void
    {
        $this->getEntityManager()->persist($conexao);
        $this->getEntityManager()->flush();
    }

    public function remover(ConexaoGoogleAgenda $conexao): void
    {
        $this->getEntityManager()->remove($conexao);
        $this->getEntityManager()->flush();
    }
}
