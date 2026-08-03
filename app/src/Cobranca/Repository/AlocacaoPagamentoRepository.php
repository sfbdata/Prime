<?php

declare(strict_types=1);

namespace App\Cobranca\Repository;

use App\Cobranca\Entity\AlocacaoPagamento;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AlocacaoPagamento>
 */
// Não-final: permite substituição por mock nos testes de UseCase e da CalculadoraSaldo.
class AlocacaoPagamentoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AlocacaoPagamento::class);
    }

    public function salvar(AlocacaoPagamento $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(AlocacaoPagamento $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Σ, em centavos, do que foi alocado às obrigações informadas — fonte da subtração do saldo
     * derivado (SPEC §10/§12, invariável 20). A `CalculadoraSaldo` passa apenas as obrigações
     * EXIGÍVEIS, então alocações a obrigações substituídas por acordo saem junto. Escopo por tenant
     * explícito.
     *
     * @param int[] $obrigacaoIds
     */
    public function totalAlocadoEmObrigacoes(array $obrigacaoIds, Tenant $tenant): int
    {
        if ($obrigacaoIds === []) {
            return 0;
        }

        return (int) $this->createQueryBuilder('a')
            ->select('COALESCE(SUM(a.valor), 0)')
            ->andWhere('a.obrigacao IN (:ids)')
            ->andWhere('a.tenant = :tenant')
            ->setParameter('ids', $obrigacaoIds)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Existe ALGUMA alocação nas obrigações informadas? Guarda do cancelamento de acordo (spec
     * `cobranca-cancelar-acordo.md` §D4).
     *
     * Pergunta a EXISTÊNCIA, não a soma: o que importa é se há dinheiro amarrado àquelas parcelas, e
     * `totalAlocadoEmObrigacoes(...) > 0` deixaria passar uma alocação de valor zero — uma linha de
     * pagamento real, com histórico, que o cancelamento tiraria da conta em silêncio.
     *
     * Escopo por tenant explícito. `$obrigacaoIds` vazio → false (acordo sem parcela nada trava).
     *
     * @param int[] $obrigacaoIds
     */
    public function existeAlocacaoEmObrigacoes(array $obrigacaoIds, Tenant $tenant): bool
    {
        if ($obrigacaoIds === []) {
            return false;
        }

        return (bool) $this->createQueryBuilder('a')
            ->select('1')
            ->andWhere('a.obrigacao IN (:ids)')
            ->andWhere('a.tenant = :tenant')
            ->setParameter('ids', $obrigacaoIds)
            ->setParameter('tenant', $tenant)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Já existe recebimento alocado NESTA obrigação COM ESTA data de pagamento?
     *
     * É a chave de idempotência do importador de Receitas (spec `cobranca-importar-receitas.md` §6):
     * reimportar o mesmo arquivo não pode criar pagamento nenhum na segunda vez. A chave é
     * `(obrigação, data de recebimento)` porque cada NN tem exatamente UMA data de recebimento no dado
     * real, e a obrigação já carrega o NN.
     *
     * Pergunta EXISTÊNCIA, não soma: alocação de valor zero também é um recebimento registrado, e
     * recriá-la duplicaria a linha do extrato mesmo sem mover um centavo.
     */
    public function existeNaObrigacaoComData(int $obrigacaoId, \DateTimeImmutable $data, Tenant $tenant): bool
    {
        return (bool) $this->createQueryBuilder('a')
            ->select('1')
            ->innerJoin('a.pagamento', 'p')
            ->andWhere('a.obrigacao = :obrigacao')
            ->andWhere('p.data = :data')
            ->andWhere('a.tenant = :tenant')
            ->setParameter('obrigacao', $obrigacaoId)
            ->setParameter('data', $data->format('Y-m-d'))
            ->setParameter('tenant', $tenant)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * As alocações de UM pagamento, por QUERY. Usado pela exclusão de recebimento (spec
     * `cobranca-excluir-recebimento.md` §3.4) para saber quanto subtrair de cada obrigação.
     *
     * Por que não `$pagamento->getAlocacoes()`: `Pagamento::$alocacoes` é o lado INVERSO
     * (`mappedBy: 'pagamento'`), e coleção inversa nasce vazia quando a entidade foi criada na mesma
     * unidade de trabalho. Num teste que cria pagamento e alocação juntos ela viria vazia, o alocado
     * final sairia igual ao Σ do banco, o reconciliador não reabriria nada — e o teste passaria assim
     * mesmo, porque em produção é outro request e a coleção carrega do banco. O defeito ficaria
     * invisível exatamente no caminho do dinheiro.
     *
     * Escopo por tenant explícito.
     *
     * @return AlocacaoPagamento[]
     */
    public function doPagamento(int $pagamentoId, Tenant $tenant): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.pagamento = :pagamento')
            ->andWhere('a.tenant = :tenant')
            ->setParameter('pagamento', $pagamentoId)
            ->setParameter('tenant', $tenant)
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Σ alocado POR OBRIGAÇÃO (centavos) das obrigações dos casos informados — versão em LOTE para a
     * agregação tenant-wide do Dashboard (Etapa 9), evitando um `totalAlocadoEmObrigacoes` por caso.
     * Retorna um mapa `obrigacaoId => Σ valor`. Escopo por tenant explícito. `$casoIds` vazio → `[]`.
     *
     * @param int[] $casoIds
     *
     * @return array<int, int>
     */
    public function somasPorObrigacaoDosCasos(array $casoIds, Tenant $tenant): array
    {
        if ($casoIds === []) {
            return [];
        }

        $linhas = $this->createQueryBuilder('a')
            ->select('IDENTITY(a.obrigacao) AS obrigacaoId', 'COALESCE(SUM(a.valor), 0) AS total')
            ->innerJoin('a.obrigacao', 'o')
            ->andWhere('o.caso IN (:casos)')
            ->andWhere('a.tenant = :tenant')
            ->andWhere('o.tenant = :tenant')
            ->groupBy('a.obrigacao')
            ->setParameter('casos', $casoIds)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getScalarResult();

        $mapa = [];
        foreach ($linhas as $linha) {
            $mapa[(int) $linha['obrigacaoId']] = (int) $linha['total'];
        }

        return $mapa;
    }
}
