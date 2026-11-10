<?php

declare(strict_types=1);

namespace App\Ponto\Repository;

use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\PontoAjuste;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * O repositório da entidade `PontoAjuste` (E10). A linhagem dos ajustes é lida junto com as batidas, pelo
 * {@see RegistroPontoRepository} (alvo do ajuste, cabeça, desconsideradas, inclusão do esquecimento), com o escritório
 * escrito em cada consulta. Aqui fica só o que a tela pergunta sobre uma lista de justificativas, numa consulta só.
 * Quem grava é a {@see \App\Ponto\Service\GravacaoDoAjusteDoPonto}.
 *
 * @extends ServiceEntityRepository<PontoAjuste>
 */
class PontoAjusteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PontoAjuste::class);
    }

    /**
     * Das justificativas informadas, as que já mexeram na folha NESTE escritório: algum ajuste (a inclusão do
     * esquecimento abonado, a desconsideração da volta para análise) aponta para elas. É a mesma regra que recusa a
     * exclusão no servidor ({@see RegistroPontoRepository::existeAjusteDaJustificativa}), lida para a lista inteira de
     * uma vez: a aba "Minhas Justificativas" esconde o "Excluir" delas sem uma consulta por linha. O escritório vai
     * escrito na consulta, no ajuste e na justificativa: id de outro escritório não volta.
     *
     * @param list<int> $justificativaIds
     *
     * @return list<int> os ids que têm ajuste, em ordem crescente
     */
    public function idsDasJustificativasComAjuste(Tenant $tenant, array $justificativaIds): array
    {
        if ($justificativaIds === []) {
            return [];
        }

        $ids = $this->createQueryBuilder('a')
            ->select('DISTINCT j.id')
            ->join('a.justificativa', 'j')
            ->andWhere('a.tenant = :tenant')
            ->andWhere('j.tenant = :tenant')
            ->andWhere('j.id IN (:ids)')
            ->setParameter('tenant', $tenant)
            ->setParameter('ids', $justificativaIds)
            ->orderBy('j.id')
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('intval', $ids);
    }
}
