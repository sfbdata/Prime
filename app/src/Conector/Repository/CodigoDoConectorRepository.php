<?php

declare(strict_types=1);

namespace App\Conector\Repository;

use App\Conector\Entity\CodigoDoConector;
use Doctrine\DBAL\LockMode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Códigos de autorização do conector. A busca pelo hash não recebe escritório: no `/oauth/token` não há escritório
 * na requisição, é o próprio código que o diz. Quem chama confere cliente, redirect e PKCE antes de usar.
 *
 * @extends ServiceEntityRepository<CodigoDoConector>
 */
class CodigoDoConectorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CodigoDoConector::class);
    }

    public function salvar(CodigoDoConector $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Busca sem escritório de propósito: no `/oauth/token` e no `/mcp` não há sessão, e o escritório só é conhecido
     * depois de achar a linha (é ela que o revela). O hash é SHA-256 de 256 bits aleatórios, único na tabela, então
     * não há colisão entre escritórios; quem chama confere tipo, validade e escritório da linha achada.
     */
    public function buscarPorIdentificadorHash(string $identificadorHash): ?CodigoDoConector
    {
        return $this->findOneBy(['identificadorHash' => $identificadorHash]);
    }

    /**
     * O código a trocar no `/oauth/token`, com a linha travada (`SELECT ... FOR UPDATE`) até o fim da transação: duas
     * trocas simultâneas do mesmo código não passam as duas pelo `usado_em` nulo, e o código reapresentado acha a linha
     * já usada (e derruba a concessão, `codigo_reutilizado`). Só funciona dentro de uma transação aberta (sem ela o
     * Doctrine lança `TransactionRequiredException`). Sem escritório pelo mesmo motivo de
     * {@see buscarPorIdentificadorHash()}.
     */
    public function buscarParaUsar(string $identificadorHash): ?CodigoDoConector
    {
        $codigo = $this->createQueryBuilder('c')
            ->andWhere('c.identificadorHash = :hash')
            ->setParameter('hash', $identificadorHash)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        return $codigo instanceof CodigoDoConector ? $codigo : null;
    }

    /**
     * Faxina (13.2): apaga, num DELETE só e em todos os escritórios, os códigos que venceram antes de `$limite` (quem
     * chama passa "agora menos 1 dia"). Usado ou não, código vencido não serve para nada. Devolve quantos saíram.
     */
    public function apagarVencidosAntesDe(\DateTimeImmutable $limite): int
    {
        return (int) $this->getEntityManager()->createQuery(
            'DELETE FROM ' . CodigoDoConector::class . ' c WHERE c.expiraEm < :limite'
        )
            ->setParameter('limite', $limite, 'datetime_immutable')
            ->execute();
    }
}
