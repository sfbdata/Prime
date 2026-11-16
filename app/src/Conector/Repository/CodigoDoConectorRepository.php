<?php

declare(strict_types=1);

namespace App\Conector\Repository;

use App\Conector\Entity\CodigoDoConector;
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
}
