<?php

declare(strict_types=1);

namespace App\Voto\Repository;

use App\Voto\Entity\Assembleia;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\VotoNominal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * O registro nominal do voto aberto. Só a pauta de voto aberto tem linhas aqui. Toda consulta filtra pelo escritório da
 * pauta, por escrito (a cédula pública roda com o TenantFilter desligado).
 *
 * @extends ServiceEntityRepository<VotoNominal>
 */
// Não-final: os testes de UseCase do Voto o substituem por mock.
class VotoNominalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VotoNominal::class);
    }

    public function salvar(VotoNominal $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Quem votou em quê na pauta de voto aberto, por bloco e identificação da unidade, com a opção junto. Pauta
     * secreta devolve vazio sem consultar.
     *
     * @return list<VotoNominal>
     */
    public function listarDaPauta(PautaDaAssembleia $pauta): array
    {
        if (!$pauta->votoAberto()) {
            return [];
        }
        /** @var list<VotoNominal> $votos */
        $votos = $this->createQueryBuilder('v')
            ->addSelect('u', 'o')
            ->innerJoin('v.unidade', 'u')
            ->leftJoin('v.opcao', 'o')
            ->andWhere('v.pauta = :pauta')
            ->andWhere('v.tenant = :tenant')
            ->setParameter('pauta', $pauta)
            ->setParameter('tenant', $pauta->getTenant())
            ->orderBy('u.bloco', 'ASC')
            ->addOrderBy('u.identificacao', 'ASC')
            ->addOrderBy('v.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $votos;
    }

    /** Reiniciar ou apagar a simulação: apaga o registro nominal do voto aberto da assembleia do escritório. */
    public function apagarDaAssembleia(Assembleia $assembleia): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM voto.assembleia_voto_nominal WHERE tenant_id = :tenant
                AND pauta_id IN (SELECT id FROM voto.assembleia_pauta WHERE assembleia_id = :assembleia AND tenant_id = :tenant)',
            ['tenant' => $assembleia->getTenant()->getId(), 'assembleia' => $assembleia->getId()],
        );
    }
}
