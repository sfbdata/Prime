<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Repository;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\Entity\Tenant\Tenant;
use App\Shared\Doctrine\Filter\AcessoALixeira;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Toda leitura leva o escritório à mão, além do `TenantFilter`: id de outro escritório volta nulo (404). As variantes
 * "mesmo na Lixeira" enxergam os documentos da Lixeira pela `AcessoALixeira`, com escopo mínimo.
 *
 * @extends ServiceEntityRepository<DocumentoParaAssinar>
 */
class DocumentoParaAssinarRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly AcessoALixeira $lixeira,
    ) {
        parent::__construct($registry, DocumentoParaAssinar::class);
    }

    /** Fora da Lixeira. */
    public function doEscritorio(int $id, Tenant $tenant): ?DocumentoParaAssinar
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.id = :id')
            ->andWhere('d.tenant = :tenant')
            ->setParameter('id', $id)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function doEscritorioMesmoNaLixeira(int $id, Tenant $tenant): ?DocumentoParaAssinar
    {
        return $this->lixeira->comLixeiraVisivel(fn (): ?DocumentoParaAssinar => $this->doEscritorio($id, $tenant));
    }

    /**
     * Os documentos dos ids pedidos que são deste escritório, estejam ou não na Lixeira. Quem chama compara a contagem
     * para saber se algum id não é dele.
     *
     * @param list<int> $ids
     *
     * @return list<DocumentoParaAssinar>
     */
    public function porIdsMesmoNaLixeira(array $ids, Tenant $tenant): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->lixeira->comLixeiraVisivel(fn (): array => $this->createQueryBuilder('d')
            ->andWhere('d.id IN (:ids)')
            ->andWhere('d.tenant = :tenant')
            ->setParameter('ids', $ids)
            ->setParameter('tenant', $tenant)
            ->orderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * A lista da tela, Lixeira incluída, do mais novo para o mais antigo, com os signatários já carregados.
     *
     * @return list<DocumentoParaAssinar>
     */
    public function listarDoEscritorio(Tenant $tenant): array
    {
        return $this->lixeira->comLixeiraVisivel(fn (): array => $this->createQueryBuilder('d')
            ->addSelect('s')
            ->leftJoin('d.signatarios', 's')
            ->andWhere('d.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('d.criadoEm', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->addOrderBy('s.ordem', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /** Grava e confirma o documento (o id nasce aqui no documento novo). */
    public function salvar(DocumentoParaAssinar $documento): void
    {
        $em = $this->getEntityManager();
        $em->persist($documento);
        $em->flush();
    }

    /** Apaga de vez (o `flush` é da transação de quem chama): signatários e eventos saem junto, pelo CASCADE. */
    public function remover(DocumentoParaAssinar $documento): void
    {
        $this->getEntityManager()->remove($documento);
    }

    /**
     * Roda o trabalho numa transação (o acréscimo à trilha trava a linha do documento e exige uma).
     *
     * @template T
     *
     * @param callable(): T $trabalho
     *
     * @return T
     */
    public function emTransacao(callable $trabalho): mixed
    {
        return $this->getEntityManager()->wrapInTransaction(static fn (): mixed => $trabalho());
    }
}
