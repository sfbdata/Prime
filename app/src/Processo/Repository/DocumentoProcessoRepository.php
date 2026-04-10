<?php

namespace App\Processo\Repository;

use App\Processo\Entity\DocumentoProcesso;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentoProcesso>
 */
class DocumentoProcessoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentoProcesso::class);
    }
}
