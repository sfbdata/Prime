<?php

declare(strict_types=1);

namespace App\Master\Repository;

use App\Entity\Auth\User;
use App\Master\DTO\MensagemDaCentral;
use App\Master\Entity\CentralMasterEstado;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * O estado (lida, estrela, arquivada) da Central de Notificações, SEMPRE de um Master só (spec
 * master-central-de-notificacoes.md, D2): toda consulta filtra por `master_id = eu`. Sem filtro de tenant de
 * propósito (D3): o Master lê todos os escritórios.
 *
 * @extends ServiceEntityRepository<CentralMasterEstado>
 */
class CentralMasterEstadoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CentralMasterEstado::class);
    }

    /**
     * Todas as marcações deste Master, pela chave "origem:id".
     *
     * @return array<string, CentralMasterEstado>
     */
    public function doMaster(User $master): array
    {
        /** @var list<CentralMasterEstado> $linhas */
        $linhas = $this->createQueryBuilder('e')
            ->andWhere('e.master = :master')
            ->setParameter('master', $master)
            ->getQuery()
            ->getResult();
        $porChave = [];
        foreach ($linhas as $estado) {
            $porChave[$estado->getOrigem() . ':' . $estado->getIdOrigem()] = $estado;
        }

        return $porChave;
    }

    public function daMensagem(User $master, string $origem, int $idOrigem): ?CentralMasterEstado
    {
        return $this->findOneBy(['master' => $master, 'origem' => $origem, 'idOrigem' => $idOrigem]);
    }

    /** A linha deste Master para a mensagem; nova (ainda não gravada) na primeira marcação. */
    public function daMensagemOuNova(User $master, MensagemDaCentral $mensagem): CentralMasterEstado
    {
        return $this->daMensagem($master, $mensagem->origem, $mensagem->idOrigem)
            ?? new CentralMasterEstado($master, $mensagem->origem, $mensagem->idOrigem, $mensagem->tenantId);
    }

    public function salvar(CentralMasterEstado $estado, bool $flush = false): void
    {
        $this->getEntityManager()->persist($estado);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
