<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Repository;

use App\TrabalhoExterno\Entity\Candidatura;
use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Entity\Vaga;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Único caminho até as candidaturas. A entidade não é `TenantAware` (a vaga e o currículo são da
 * plataforma): toda consulta parte de uma vaga já localizada no escopo de quem pede, do currículo do
 * próprio usuário ou, para a Central com `talentos`, do id ({@see daCentralPorId()}). Não há `find`
 * solto.
 *
 * @extends ServiceEntityRepository<Candidatura>
 */
class CandidaturaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Candidatura::class);
    }

    public function salvar(Candidatura $candidatura, bool $flush = false): void
    {
        $this->getEntityManager()->persist($candidatura);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function daVagaEDoCurriculo(Vaga $vaga, Curriculo $curriculo): ?Candidatura
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.vaga = :vaga')
            ->andWhere('c.curriculo = :curriculo')
            ->setParameter('vaga', $vaga)
            ->setParameter('curriculo', $curriculo)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Só para a Central com `talentos` (quem chama confere): mudar o status de um candidato. */
    public function daCentralPorId(int $id): ?Candidatura
    {
        return $this->createQueryBuilder('c')
            ->addSelect('v')
            ->join('c.vaga', 'v')
            ->andWhere('c.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Os candidatos de uma vaga, com o currículo, na ordem em que chegaram.
     *
     * @return list<Candidatura>
     */
    public function daVaga(Vaga $vaga): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('cv')
            ->join('c.curriculo', 'cv')
            ->andWhere('c.vaga = :vaga')
            ->setParameter('vaga', $vaga)
            ->orderBy('c.enviadaEm', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * As candidaturas de um currículo, com a vaga, da mais recente para a mais antiga ("Minhas
     * candidaturas", l.1103).
     *
     * @return list<Candidatura>
     */
    public function doCurriculo(Curriculo $curriculo): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('v')
            ->join('c.vaga', 'v')
            ->andWhere('c.curriculo = :curriculo')
            ->setParameter('curriculo', $curriculo)
            ->orderBy('c.enviadaEm', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * As vagas às quais o currículo foi enviado ao escritório, com o dono de cada uma.
     *
     * @return list<Vaga>
     */
    public function vagasEnviadasDo(Curriculo $curriculo): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('v')
            ->from(Vaga::class, 'v')
            ->join(Candidatura::class, 'c', 'WITH', 'c.vaga = v')
            ->andWhere('c.curriculo = :curriculo')
            ->andWhere('c.enviadoAoEscritorio = true')
            ->setParameter('curriculo', $curriculo)
            ->getQuery()
            ->getResult();
    }

    /** O usuário tem candidatura nesta vaga (ele abre o detalhe, mesmo fechada, por "Minhas candidaturas"). */
    public function existeDoCurriculoNaVaga(Curriculo $curriculo, int $vagaId): bool
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.curriculo = :curriculo')
            ->andWhere('IDENTITY(c.vaga) = :vaga')
            ->setParameter('curriculo', $curriculo)
            ->setParameter('vaga', $vagaId)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Os aceites da rodada atual (os que guardaram o número da rodada da vaga): cada "Ofertar novamente"
     * ou "Reabrir" conta do zero. Lido depois da trava da linha da vaga.
     *
     * @return list<Candidatura>
     */
    public function aceitesDaRodada(Vaga $vaga): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('cv')
            ->join('c.curriculo', 'cv')
            ->andWhere('c.vaga = :vaga')
            ->andWhere('c.aceiteDeOferta = true')
            ->andWhere('c.rodada = :rodada')
            ->setParameter('vaga', $vaga)
            ->setParameter('rodada', $vaga->getRodada())
            ->orderBy('c.enviadaEm', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Os números de cada vaga para os cartões, numa consulta: total, recebidas (para analisar),
     * enviadas ao escritório e aceites da rodada atual.
     *
     * @param list<int> $vagas
     *
     * @return array<int, array{total: int, recebidas: int, enviados: int, aceites: int}>
     */
    public function resumoPorVaga(array $vagas): array
    {
        if ($vagas === []) {
            return [];
        }
        $linhas = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.vaga) AS vaga', 'COUNT(c.id) AS total')
            ->addSelect("SUM(CASE WHEN c.status = 'recebida' THEN 1 ELSE 0 END) AS recebidas")
            ->addSelect('SUM(CASE WHEN c.enviadoAoEscritorio = true THEN 1 ELSE 0 END) AS enviados')
            ->addSelect('SUM(CASE WHEN c.aceiteDeOferta = true AND c.rodada = v.rodada THEN 1 ELSE 0 END) AS aceites')
            ->join('c.vaga', 'v')
            ->andWhere('c.vaga IN (:vagas)')
            ->setParameter('vagas', $vagas)
            ->groupBy('c.vaga')
            ->getQuery()
            ->getArrayResult();
        $mapa = [];
        foreach ($linhas as $l) {
            $mapa[(int) $l['vaga']] = ['total' => (int) $l['total'], 'recebidas' => (int) $l['recebidas'], 'enviados' => (int) $l['enviados'], 'aceites' => (int) $l['aceites']];
        }

        return $mapa;
    }

    /**
     * Quantas candidaturas cada currículo tem (`impC` +2, coluna do banco).
     *
     * @param list<int> $curriculos
     *
     * @return array<int, int> id do currículo → candidaturas
     */
    public function contarPorCurriculo(array $curriculos): array
    {
        if ($curriculos === []) {
            return [];
        }
        $linhas = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.curriculo) AS curriculo', 'COUNT(c.id) AS n')
            ->andWhere('c.curriculo IN (:curriculos)')
            ->setParameter('curriculos', $curriculos)
            ->groupBy('c.curriculo')
            ->getQuery()
            ->getArrayResult();

        return array_column(array_map(static fn (array $l): array => ['id' => (int) $l['curriculo'], 'n' => (int) $l['n']], $linhas), 'n', 'id');
    }

    /** @return list<int> os ids das candidaturas da vaga, para apagar as marcações antes dela */
    public function idsDaVaga(Vaga $vaga): array
    {
        return array_map('intval', $this->createQueryBuilder('c')->select('c.id')->andWhere('c.vaga = :vaga')->setParameter('vaga', $vaga)->getQuery()->getSingleColumnResult());
    }

    /** @return list<int> os ids das candidaturas do currículo, para apagar as marcações antes dele */
    public function idsDoCurriculo(Curriculo $curriculo): array
    {
        return array_map('intval', $this->createQueryBuilder('c')->select('c.id')->andWhere('c.curriculo = :cv')->setParameter('cv', $curriculo)->getQuery()->getSingleColumnResult());
    }
}
