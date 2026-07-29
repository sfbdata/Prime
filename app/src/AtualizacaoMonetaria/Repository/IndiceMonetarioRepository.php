<?php

declare(strict_types=1);

namespace App\AtualizacaoMonetaria\Repository;

use App\AtualizacaoMonetaria\Entity\IndiceMonetario;
use App\AtualizacaoMonetaria\Enum\SerieIndice;
use App\AtualizacaoMonetaria\Service\TabelaIndices;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndiceMonetario>
 */
// ⚠️ **Sem filtro de tenant, de propósito.** É a única exceção do projeto à regra de isolamento,
// justificada na spec §7.1: índice oficial de inflação é dado público, igual para todo escritório.
// Nenhum método aqui recebe Tenant porque nenhuma linha desta tabela pertence a um.
// Não-final: permite substituição por mock nos testes.
class IndiceMonetarioRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndiceMonetario::class);
    }

    public function salvar(IndiceMonetario $indice, bool $flush = false): void
    {
        $this->getEntityManager()->persist($indice);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Competência mais recente já importada da série.
     *
     * É por série, não global: medido em 29/07/2026, a taxa legal já tinha 07/2026 publicada
     * enquanto o INPC parava em 06/2026.
     */
    public function ultimaCompetenciaPublicada(SerieIndice $serie): ?\DateTimeImmutable
    {
        $competencia = $this->createQueryBuilder('i')
            ->select('i.competencia')
            ->andWhere('i.serie = :serie')
            ->setParameter('serie', $serie)
            ->orderBy('i.competencia', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($competencia === null) {
            return null;
        }

        $data = $competencia['competencia'];

        return $data instanceof \DateTimeImmutable ? $data : null;
    }

    /**
     * Monta a fotografia em memória consumida pelo motor de cálculo (Parte 3).
     *
     * Consulta escalar (sem hidratar entidade): são ~1.100 linhas somando as três séries, e o motor
     * só precisa do par competência→variação.
     */
    public function carregarTabela(): TabelaIndices
    {
        /** @var list<array{serie: SerieIndice, competencia: \DateTimeImmutable, variacaoPct: string}> $linhas */
        $linhas = $this->createQueryBuilder('i')
            ->select('i.serie', 'i.competencia', 'i.variacaoPct')
            ->orderBy('i.serie', 'ASC')
            ->addOrderBy('i.competencia', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $variacoes = [];
        foreach ($linhas as $linha) {
            $variacoes[$linha['serie']->value][$linha['competencia']->format('Y-m-01')] = $linha['variacaoPct'];
        }

        return new TabelaIndices($variacoes);
    }

    /**
     * Série inteira indexada por competência 'Y-m-01', para o importador decidir INSERT × UPDATE
     * numa consulta só (em vez de uma por mês).
     *
     * @return array<string, IndiceMonetario>
     */
    public function mapaPorCompetencia(SerieIndice $serie): array
    {
        $indices = $this->createQueryBuilder('i')
            ->andWhere('i.serie = :serie')
            ->setParameter('serie', $serie)
            ->orderBy('i.competencia', 'ASC')
            ->getQuery()
            ->getResult();

        $mapa = [];
        foreach ($indices as $indice) {
            $mapa[$indice->chaveCompetencia()] = $indice;
        }

        return $mapa;
    }
}
