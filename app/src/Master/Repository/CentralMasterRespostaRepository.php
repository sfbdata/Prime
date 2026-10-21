<?php

declare(strict_types=1);

namespace App\Master\Repository;

use App\Master\Entity\CentralMasterResposta;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * O histórico de respostas da Central de Notificações, compartilhado entre os Masters (spec
 * master-central-de-notificacoes.md, D1). Sem filtro de tenant de propósito (D3).
 *
 * @extends ServiceEntityRepository<CentralMasterResposta>
 */
class CentralMasterRespostaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CentralMasterResposta::class);
    }

    /**
     * As mensagens que já têm resposta, pela chave "origem:id".
     *
     * @return array<string, true>
     */
    public function chavesRespondidas(): array
    {
        $linhas = $this->createQueryBuilder('r')
            ->select('r.origem AS origem', 'r.idOrigem AS idOrigem')
            ->groupBy('r.origem', 'r.idOrigem')
            ->getQuery()
            ->getArrayResult();
        $chaves = [];
        foreach ($linhas as $linha) {
            $chaves[$linha['origem'] . ':' . $linha['idOrigem']] = true;
        }

        return $chaves;
    }

    /** @return list<CentralMasterResposta> as mais antigas primeiro */
    public function daMensagem(string $origem, int $idOrigem): array
    {
        /** @var list<CentralMasterResposta> */
        return $this->createQueryBuilder('r')
            ->andWhere('r.origem = :origem')
            ->andWhere('r.idOrigem = :id')
            ->setParameter('origem', $origem)
            ->setParameter('id', $idOrigem)
            ->orderBy('r.em', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function salvar(CentralMasterResposta $resposta, bool $flush = false): void
    {
        $this->getEntityManager()->persist($resposta);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Uma transação só para o envio (o Carteiro grava pela mesma conexão, e o DBAL aninha por savepoint), a resposta e
     * o que a origem guarda: se a gravação falhar, o e-mail não fica na fila sem histórico.
     *
     * @template T
     *
     * @param callable(): T $trabalho
     *
     * @return T
     */
    public function emTransacao(callable $trabalho): mixed
    {
        return $this->getEntityManager()->wrapInTransaction(static fn (): mixed => $trabalho());
    }
}
