<?php

declare(strict_types=1);

namespace App\ServiceDesk\Repository;

use App\ServiceDesk\Entity\PrecoDoAtendimento;
use App\ServiceDesk\Enum\TipoDeChamado;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PrecoDoAtendimento>
 */
class PrecoDoAtendimentoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PrecoDoAtendimento::class);
    }

    public function salvar(PrecoDoAtendimento $preco, bool $flush = false): void
    {
        $this->getEntityManager()->persist($preco);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(PrecoDoAtendimento $preco, bool $flush = false): void
    {
        $this->getEntityManager()->remove($preco);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function doTipo(TipoDeChamado $tipo): ?PrecoDoAtendimento
    {
        return $this->findOneBy(['tipo' => $tipo]);
    }

    /** @return array<string, int> valor em centavos por tipo (só os que têm preço) */
    public function valoresPorTipo(): array
    {
        $valores = [];
        foreach ($this->findAll() as $preco) {
            $valores[$preco->getTipo()->value] = $preco->getValorCentavos();
        }

        return $valores;
    }
}
