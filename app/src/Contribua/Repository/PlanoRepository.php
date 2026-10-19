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

    public function salvar(Plano $plano, bool $flush = false): void
    {
        $this->getEntityManager()->persist($plano);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Quantos escritórios (fora da quarentena) estão em cada plano. Escritório sem linha de espaço está
     * no Free. SQL cru: atravessa todos os escritórios, sem o filtro da sessão.
     *
     * @return array<string, int> código do plano => escritórios
     */
    public function escritoriosPorPlano(): array
    {
        $linhas = $this->getEntityManager()->getConnection()->fetchAllKeyValue(
            "SELECT COALESCE(p.codigo, 'free') AS codigo, COUNT(*) AS escritorios
               FROM tenant t
          LEFT JOIN contribua_espaco_do_escritorio e ON e.tenant_id = t.id
          LEFT JOIN contribua_plano p ON p.id = e.plano_id
              WHERE t.excluido_em IS NULL
           GROUP BY COALESCE(p.codigo, 'free')",
        );

        return array_map('intval', $linhas);
    }

    /** @return list<Plano> */
    public function ativosEmOrdem(): array
    {
        return $this->findBy(['ativo' => true], ['ordem' => 'ASC']);
    }
}
