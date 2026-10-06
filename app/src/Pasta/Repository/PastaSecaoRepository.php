<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaSecao;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PastaSecao>
 */
class PastaSecaoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PastaSecao::class);
    }

    /** @return PastaSecao[] */
    public function findByPasta(Pasta $pasta, Tenant $tenant): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.pasta = :pasta')
            ->andWhere('s.tenant = :tenant')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->orderBy('s.ordem', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Próxima ordem entre as IRMÃS de $pai (ou entre as seções da raiz, se $pai for null). */
    public function proximaOrdem(Pasta $pasta, Tenant $tenant, ?PastaSecao $pai = null): int
    {
        $qb = $this->createQueryBuilder('s')
            ->select('MAX(s.ordem)')
            ->andWhere('s.pasta = :pasta')
            ->andWhere('s.tenant = :tenant')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant);

        if ($pai === null) {
            $qb->andWhere('s.pai IS NULL');
        } else {
            $qb->andWhere('s.pai = :pai')->setParameter('pai', $pai);
        }

        $max = $qb->getQuery()->getSingleScalarResult();

        return ($max === null ? 0 : (int) $max) + 1;
    }

    /**
     * Conta o que a exclusão de $secao levaria junto. As duas contagens têm escopos DIFERENTES,
     * de propósito, porque é o que o aviso precisa dizer ("contém 3 subpastas e 127 arquivos"):
     *
     *   - subpastas: só as DESCENDENTES; a própria $secao não se conta;
     *   - arquivos:  os da própria $secao MAIS os de toda a descendência.
     *
     * Nunca inclui os documentos que estão na raiz da pasta (secao_id IS NULL) — esses sobrevivem.
     *
     * PRÉ-CONDIÇÃO: $secao (e cada filha visitada na recursão) precisa vir CARREGADA DO BANCO,
     * com as coleções lazy intactas — é o que acontece naturalmente com um `$em->find(...)` novo
     * por request, como fazem os dois consumidores atuais (PastaSecaoController::excluir/renomear).
     * O motivo: `PastaDocumento::setSecao()` só grava a FK no lado dono da associação, sem
     * sincronizar `PastaSecao::$documentos` (diferente de `setPai()`, que sincroniza `pai`/`filhas`
     * nos dois sentidos de propósito). Se alguém associar documentos a uma $secao só em memória,
     * dentro da mesma Unit of Work, e chamar este método sem recarregar essa seção do banco, a
     * coleção `documentos` já estará "presa" vazia (ou incompleta) desde o primeiro flush — e o
     * `arquivos` sai SUBCONTADO. Não estoura exceção nenhuma: é um número errado com cara de
     * certo, silencioso, do jeito mais perigoso de errar.
     *
     * O corte em PastaSecao::LIMITE_SEGURANCA não é o teto de produto (10, validado nos UseCases
     * ao criar/mover) — é proteção contra ciclo GRAVADO NO BANCO, que viraria recursão infinita.
     * `DesfazerAlteracaoAuditLogUseCase` grava `pai` direto pelo setter da entidade, sem passar
     * pelos UseCases nem pelos guards de ciclo deles — é o caminho que provou o estouro de
     * memória (ver o teste que monta `a.pai = b; b.pai = a` à mão).
     *
     * @return array{subpastas: int, arquivos: int}
     */
    public function contarConteudoRecursivo(PastaSecao $secao, int $profundidade = 0): array
    {
        if ($profundidade >= PastaSecao::LIMITE_SEGURANCA) {
            return ['subpastas' => 0, 'arquivos' => 0];
        }

        $subpastas = 0;
        $arquivos  = $secao->getDocumentos()->count();

        foreach ($secao->getFilhas() as $filha) {
            $abaixo = $this->contarConteudoRecursivo($filha, $profundidade + 1);
            $subpastas += 1 + $abaixo['subpastas'];
            $arquivos  += $abaixo['arquivos'];
        }

        return ['subpastas' => $subpastas, 'arquivos' => $arquivos];
    }

    /**
     * As seções de $ids que pertencem a ESTA pasta e a ESTE escritório — a prova de posse em lote
     * (D4). Quem chama compara a contagem com a de ids pedidos: id que não voltou é de outra pasta
     * ou não existe → 404 sem efeito parcial.
     *
     * @param list<int> $ids
     *
     * @return list<PastaSecao>
     */
    public function findTodasDaPasta(array $ids, Pasta $pasta, Tenant $tenant): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<PastaSecao> $secoes */
        $secoes = $this->createQueryBuilder('s')
            ->andWhere('s.id IN (:ids)')
            ->andWhere('s.pasta = :pasta')
            ->andWhere('s.tenant = :tenant')
            ->setParameter('ids', $ids)
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $secoes;
    }

    public function findByIdAndPastaAndTenant(int $id, Pasta $pasta, Tenant $tenant): ?PastaSecao
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.id = :id')
            ->andWhere('s.pasta = :pasta')
            ->andWhere('s.tenant = :tenant')
            ->setParameter('id', $id)
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    // ── Lixeira (D7) — só fazem sentido com o LixeiraFilter desligado (`AcessoALixeira`) ─────────

    /**
     * As seções de $ids que estão NA LIXEIRA desta pasta e deste escritório — a prova de posse do
     * restaurar (mesma regra do `findTodasDaPasta`: contagem diferente → 404 sem efeito). A
     * condição `excluidoEm IS NOT NULL` é explícita: item vivo não é "restaurável".
     *
     * @param list<int> $ids
     *
     * @return list<PastaSecao>
     */
    public function findNaLixeiraDaPasta(array $ids, Pasta $pasta, Tenant $tenant): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<PastaSecao> $secoes */
        $secoes = $this->createQueryBuilder('s')
            ->andWhere('s.id IN (:ids)')
            ->andWhere('s.pasta = :pasta')
            ->andWhere('s.tenant = :tenant')
            ->andWhere('s.excluidoEm IS NOT NULL')
            ->setParameter('ids', $ids)
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $secoes;
    }

    /**
     * As seções na lixeira de uma pasta, da excluída mais recente à mais antiga, com o pai e quem
     * excluiu já carregados (a listagem da UI da lixeira).
     *
     * @return list<PastaSecao>
     */
    public function listarLixeiraDaPasta(Pasta $pasta, Tenant $tenant): array
    {
        /** @var list<PastaSecao> $secoes */
        $secoes = $this->createQueryBuilder('s')
            ->addSelect('p', 'u')
            ->leftJoin('s.pai', 'p')
            ->leftJoin('s.excluidoPor', 'u')
            ->andWhere('s.pasta = :pasta')
            ->andWhere('s.tenant = :tenant')
            ->andWhere('s.excluidoEm IS NOT NULL')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->orderBy('s.excluidoEm', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $secoes;
    }

    /**
     * A fila da purga: ids das seções excluídas ANTES de $corte, da mais antiga à mais nova.
     *
     * @return list<int>
     */
    public function idsNaLixeiraVencida(\DateTimeImmutable $corte, ?int $limite = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->select('s.id')
            ->andWhere('s.excluidoEm IS NOT NULL')
            ->andWhere('s.excluidoEm < :corte')
            ->setParameter('corte', $corte)
            ->orderBy('s.excluidoEm', 'ASC')
            ->addOrderBy('s.id', 'ASC');

        if ($limite !== null) {
            $qb->setMaxResults($limite);
        }

        return array_map('intval', $qb->getQuery()->getSingleColumnResult());
    }

    public function contarNaLixeiraVencida(\DateTimeImmutable $corte): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.excluidoEm IS NOT NULL')
            ->andWhere('s.excluidoEm < :corte')
            ->setParameter('corte', $corte)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function salvar(PastaSecao $secao, bool $flush = false): void
    {
        $this->getEntityManager()->persist($secao);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(PastaSecao $secao, bool $flush = false): void
    {
        $this->getEntityManager()->remove($secao);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
