<?php

declare(strict_types=1);

namespace App\Agenda\Repository;

use App\Agenda\Entity\InscricaoDePush;
use App\Entity\Auth\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InscricaoDePush> */
class InscricaoDePushRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InscricaoDePush::class);
    }

    /** Em SQL de propósito: o filtro de escritório esconderia a linha gravada em outro escritório. */
    public function removerDaPessoa(string $endpoint, User $usuario): int
    {
        return $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM agenda_push_inscricao WHERE endpoint_hash = :hash AND usuario_id = :usuario',
            ['hash' => InscricaoDePush::hashDo($endpoint), 'usuario' => $usuario->getId()],
        );
    }

    /**
     * Todas as inscrições de pessoas e escritórios ativos: a varredura roda fora de requisição (worker) e cuida
     * do escritório de cada uma.
     *
     * @return list<InscricaoDePush>
     */
    public function todas(): array
    {
        return $this->createQueryBuilder('i')
            ->addSelect('u', 't')
            ->join('i.usuario', 'u')
            ->join('i.tenant', 't')
            ->andWhere('u.isActive = true')
            ->andWhere('t.isActive = true')
            ->orderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Grava a inscrição do navegador; se o endereço já existe, a linha muda de pessoa, escritório, chaves e
     * fuso. Em SQL atômico de propósito: o filtro de escritório esconderia a linha de outro escritório e o
     * índice único barraria a nova, e dois pedidos ao mesmo tempo também não colidem.
     */
    public function gravar(InscricaoDePush $inscricao): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO agenda_push_inscricao (endpoint_hash, criado_em, endpoint, p256dh, auth, fuso, tenant_id, usuario_id)
             VALUES (:hash, :criado, :endpoint, :p256dh, :auth, :fuso, :tenant, :usuario)
             ON CONFLICT (endpoint_hash) DO UPDATE SET p256dh = EXCLUDED.p256dh, auth = EXCLUDED.auth,
                 fuso = EXCLUDED.fuso, tenant_id = EXCLUDED.tenant_id, usuario_id = EXCLUDED.usuario_id',
            [
                'hash'     => InscricaoDePush::hashDo($inscricao->getEndpoint()),
                'criado'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'endpoint' => $inscricao->getEndpoint(),
                'p256dh'   => $inscricao->getP256dh(),
                'auth'     => $inscricao->getAuth(),
                'fuso'     => $inscricao->getFuso(),
                'tenant'   => $inscricao->getTenant()->getId(),
                'usuario'  => $inscricao->getUsuario()->getId(),
            ],
        );
    }

    /** @param list<InscricaoDePush> $inscricoes */
    public function remover(array $inscricoes): void
    {
        foreach ($inscricoes as $i) {
            $this->getEntityManager()->remove($i);
        }
        $this->getEntityManager()->flush();
    }
}
