<?php

declare(strict_types=1);

namespace App\Contribua\Repository;

use App\Contribua\Entity\Plano;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Plano>
 */
// Não-final: os testes de UseCase mockam o repositório.
class PlanoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Plano::class);
    }

    /** O plano em que toda conta nova começa. A migration o cria; faltar é defeito de instalação. */
    public function free(): Plano
    {
        $plano = $this->findOneBy(['codigo' => Plano::CODIGO_FREE]);
        if (!$plano instanceof Plano) {
            throw new \LogicException('O plano Free não está cadastrado (migration de contribua_plano não aplicada).');
        }

        return $plano;
    }

    /** @return list<Plano> */
    public function ativosEmOrdem(): array
    {
        return $this->findBy(['ativo' => true], ['ordem' => 'ASC']);
    }
}
