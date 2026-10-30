<?php

declare(strict_types=1);

namespace App\Calculo\Repository;

use App\Calculo\Entity\CalculoEmitido;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * A consulta por código é PÚBLICA (página de verificação, sem login nem tenant): por isso não filtra por escritório,
 * e o que ela devolve é só o que o PDF já mostra.
 *
 * @extends ServiceEntityRepository<CalculoEmitido>
 */
class CalculoEmitidoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CalculoEmitido::class);
    }

    public function porCodigo(string $codigo): ?CalculoEmitido
    {
        return $this->findOneBy(['codigo' => $codigo]);
    }

    public function existeCodigo(string $codigo): bool
    {
        return $this->porCodigo($codigo) !== null;
    }

    public function salvar(CalculoEmitido $emitido): void
    {
        $em = $this->getEntityManager();
        $em->persist($emitido);
        $em->flush();
    }
}
