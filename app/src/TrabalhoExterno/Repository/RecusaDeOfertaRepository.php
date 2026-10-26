<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Repository;

use App\Entity\Auth\User;
use App\TrabalhoExterno\Entity\RecusaDeOferta;
use App\TrabalhoExterno\Entity\Vaga;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * As recusas de oferta (D11b): sempre pela vaga já localizada no escopo de quem lê e pelo próprio usuário.
 *
 * @extends ServiceEntityRepository<RecusaDeOferta>
 */
class RecusaDeOfertaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecusaDeOferta::class);
    }

    public function daVagaEDoUsuario(Vaga $vaga, User $usuario): ?RecusaDeOferta
    {
        return $this->findOneBy(['vaga' => $vaga, 'usuario' => $usuario]);
    }

    public function salvar(RecusaDeOferta $recusa, bool $flush = false): void
    {
        $this->getEntityManager()->persist($recusa);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(RecusaDeOferta $recusa, bool $flush = false): void
    {
        $this->getEntityManager()->remove($recusa);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
