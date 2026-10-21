<?php

declare(strict_types=1);

namespace App\Master\Repository;

use App\Master\Entity\SeccionalOab;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * As 27 seccionais da OAB do mapa do Painel do Master. Sem filtro de tenant de propósito: tabela de
 * PLATAFORMA (ver {@see SeccionalOab}); quem chama são os UseCases do Master, atrás de ROLE_SUPER_ADMIN.
 *
 * @extends ServiceEntityRepository<SeccionalOab>
 */
class SeccionalOabRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeccionalOab::class);
    }

    public function buscarPorUf(string $uf): ?SeccionalOab
    {
        return $this->findOneBy(['uf' => $uf]);
    }

    /** @return list<SeccionalOab> em ordem de UF (a ordem dos chips) */
    public function todasEmOrdem(): array
    {
        /** @var list<SeccionalOab> */
        return $this->createQueryBuilder('s')->orderBy('s.uf', 'ASC')->getQuery()->getResult();
    }

    /**
     * Pessoas inscritas em cada seccional ("N usuários" do cartão da sede): inscrição principal OU
     * suplementar em `inscricao_oab`, uma pessoa conta uma vez por UF (COUNT DISTINCT). Só conta quem o
     * painel chama de pessoa: `user` ativo com ao menos um vínculo ativo em escritório fora da quarentena
     * (o mesmo recorte de PainelMasterRepository::vinculosAtivos()). Qualquer situação da inscrição
     * conta (DONO: só a ativa ou todas). SQL direto: atravessa todos os escritórios, sem filtro do ORM.
     *
     * @return array<string, int> UF => pessoas
     */
    public function usuariosPorUf(): array
    {
        $linhas = $this->getEntityManager()->getConnection()->fetchAllKeyValue(
            'SELECT io.uf, COUNT(DISTINCT io.user_id)
               FROM inscricao_oab io
               JOIN "user" u ON u.id = io.user_id AND u.is_active = TRUE
              WHERE EXISTS (SELECT 1 FROM user_tenant ut
                              JOIN tenant t ON t.id = ut.tenant_id AND t.excluido_em IS NULL
                             WHERE ut.user_id = io.user_id AND ut.is_active = TRUE)
           GROUP BY io.uf',
        );

        return array_map(intval(...), $linhas);
    }

    public function salvar(SeccionalOab $seccional, bool $flush = false): void
    {
        $this->getEntityManager()->persist($seccional);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
