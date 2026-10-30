<?php

declare(strict_types=1);

namespace App\Calculo\Repository;

use App\Calculo\Entity\CalculoEmitido;
use App\ServiceDesk\Repository\SemFiltroDeTenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * A consulta por código é PÚBLICA (página de verificação): atravessa os escritórios de propósito e ignora o
 * `TenantFilter`, porque quem confere pode estar logado em OUTRO escritório. Devolve só o que o PDF já mostra.
 *
 * @extends ServiceEntityRepository<CalculoEmitido>
 */
class CalculoEmitidoRepository extends ServiceEntityRepository
{
    use SemFiltroDeTenant;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CalculoEmitido::class);
    }

    public function porCodigo(string $codigo): ?CalculoEmitido
    {
        return $this->semFiltroDeTenant(fn (): ?CalculoEmitido => $this->findOneBy(['codigo' => $codigo]));
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
