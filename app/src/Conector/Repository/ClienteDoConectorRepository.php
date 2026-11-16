<?php

declare(strict_types=1);

namespace App\Conector\Repository;

use App\Conector\Entity\ClienteDoConector;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Clientes OAuth do conector. Global por natureza ({@see ClienteDoConector}): a busca pelo identificador não filtra
 * escritório, porque o mesmo app atende todos.
 *
 * @extends ServiceEntityRepository<ClienteDoConector>
 */
class ClienteDoConectorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClienteDoConector::class);
    }

    public function salvar(ClienteDoConector $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** O cliente pelo `client_id` que o app mandou (a URL, no CIMD). */
    public function buscarPorIdentificador(string $identificador): ?ClienteDoConector
    {
        return $this->findOneBy(['identificador' => $identificador]);
    }
}
