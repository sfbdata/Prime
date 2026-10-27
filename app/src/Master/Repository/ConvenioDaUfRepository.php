<?php

declare(strict_types=1);

namespace App\Master\Repository;

use App\Master\Entity\ConvenioDaUf;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Os convênios OAB por estado que NÃO são gratuitos (`convenio_uf`). Sem filtro de tenant de propósito: é configuração
 * GLOBAL da plataforma (ver {@see ConvenioDaUf}).
 *
 * @extends ServiceEntityRepository<ConvenioDaUf>
 */
class ConvenioDaUfRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConvenioDaUf::class);
    }

    /** @return array<string, ConvenioDaUf> sigla => convênio */
    public function todosPorUf(): array
    {
        $porUf = [];
        foreach ($this->findAll() as $convenio) {
            $porUf[$convenio->getUf()] = $convenio;
        }

        return $porUf;
    }

    public function buscar(string $uf): ?ConvenioDaUf
    {
        return $this->find($uf);
    }

    public function salvar(ConvenioDaUf $convenio, bool $flush = false): void
    {
        $this->getEntityManager()->persist($convenio);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(ConvenioDaUf $convenio, bool $flush = false): void
    {
        $this->getEntityManager()->remove($convenio);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
