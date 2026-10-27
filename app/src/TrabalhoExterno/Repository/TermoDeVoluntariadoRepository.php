<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Repository;

use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Entity\TermoDeVoluntariado;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Único caminho até os termos. A entidade não é `TenantAware` (o voluntário a lê por qualquer escritório): toda
 * consulta parte de um currículo que o `LocalizadorDeCurriculo` já escopou, ou do termo achado pelo código e
 * conferido pela `PoliticaDoTermo`. Nenhuma consulta devolve o conjunto de termos sem currículo.
 *
 * @extends ServiceEntityRepository<TermoDeVoluntariado>
 */
class TermoDeVoluntariadoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TermoDeVoluntariado::class);
    }

    public function salvar(TermoDeVoluntariado $termo, bool $flush = false): void
    {
        $this->getEntityManager()->persist($termo);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(TermoDeVoluntariado $termo): void
    {
        $this->getEntityManager()->remove($termo);
    }

    /** O código (`TV-0001`) é único: a sequência só cresce. */
    public function porCodigo(string $codigo): ?TermoDeVoluntariado
    {
        return TermoDeVoluntariado::codigoValido($codigo) ? $this->findOneBy(['codigo' => $codigo]) : null;
    }

    /**
     * O próximo código legível (`TV-0001`), maior que o de qualquer termo já gravado. Só vale dentro da transação que
     * grava o termo: o lock consultivo da transação serializa quem pede ao mesmo tempo, e a sequência só cresce (nunca repete).
     */
    public function proximoCodigo(): string
    {
        $conn = $this->getEntityManager()->getConnection();
        $conn->executeQuery("SELECT pg_advisory_xact_lock(hashtext('trabalho_externo_termo_codigo'))");
        $proximo = (int) $conn->fetchOne('SELECT COALESCE(MAX(id), 0) + 1 FROM trabalho_externo_termo_voluntariado');

        return 'TV-' . str_pad((string) $proximo, 4, '0', STR_PAD_LEFT);
    }

    /** O termo mais recente do currículo (o estado do currículo é o dele), ou null. */
    public function maisRecenteDo(Curriculo $curriculo): ?TermoDeVoluntariado
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.curriculo = :cv')
            ->setParameter('cv', $curriculo)
            ->orderBy('t.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<TermoDeVoluntariado> todos os termos do currículo, do mais novo para o mais antigo */
    public function doCurriculo(Curriculo $curriculo): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.curriculo = :cv')
            ->setParameter('cv', $curriculo)
            ->orderBy('t.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * O termo mais recente de cada currículo da lista, em UMA consulta.
     *
     * @param list<int> $idsDosCurriculos
     *
     * @return array<int, TermoDeVoluntariado> id do currículo → termo
     */
    public function maisRecentesDe(array $idsDosCurriculos): array
    {
        if ($idsDosCurriculos === []) {
            return [];
        }
        $termos = $this->createQueryBuilder('t')
            ->andWhere('IDENTITY(t.curriculo) IN (:ids)')
            ->andWhere('t.id = (SELECT MAX(t2.id) FROM ' . TermoDeVoluntariado::class . ' t2 WHERE t2.curriculo = t.curriculo)')
            ->setParameter('ids', $idsDosCurriculos)
            ->getQuery()
            ->getResult();
        $porCurriculo = [];
        foreach ($termos as $termo) {
            $porCurriculo[(int) $termo->getCurriculo()->getId()] = $termo;
        }

        return $porCurriculo;
    }
}
