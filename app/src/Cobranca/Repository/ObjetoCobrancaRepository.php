<?php

declare(strict_types=1);

namespace App\Cobranca\Repository;

use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\AbstractQuery;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ObjetoCobranca>
 */
// Não-final: permite substituição por mock nos testes de UseCase.
class ObjetoCobrancaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ObjetoCobranca::class);
    }

    public function salvar(ObjetoCobranca $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(ObjetoCobranca $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Busca tenant-safe por id — o filtro SQL do Doctrine NÃO se aplica a find() por PK
     * (risco cross-tenant). Sempre passar o tenant explícito.
     */
    public function findOneByIdDoTenant(int $id, Tenant $tenant): ?ObjetoCobranca
    {
        return $this->findOneBy(['id' => $id, 'tenant' => $tenant]);
    }

    /**
     * Objeto de uma carteira por sua identificação (dedup da importação — decisão C). Escopo por
     * tenant SEMPRE (a carteira já é tenant-bound; o tenant é defesa em profundidade). Usado para NÃO
     * recriar o mesmo objeto (unidade) na reimportação.
     */
    public function findOnePorIdentificacaoNaCarteira(Carteira $carteira, string $identificacao, Tenant $tenant): ?ObjetoCobranca
    {
        return $this->findOneBy([
            'carteira' => $carteira,
            'identificacao' => $identificacao,
            'tenant' => $tenant,
        ]);
    }

    /**
     * Ids da unidade ANTERIOR e da PRÓXIMA dentro da mesma carteira, para as setas `‹ ›` do cabeçalho
     * do objeto (spec §1.5). `null` de cada lado = está na ponta, e a seta correspondente fica
     * desabilitada.
     *
     * **Ordem `identificacao ASC, id ASC`, deliberadamente diferente da listagem da carteira**
     * (`atualizadoEm DESC`): aquela muda sozinha a cada registro de contato ou pagamento, e a mesma
     * seta levaria a lugares diferentes a cada visita. Aqui a vizinhança é estável.
     *
     * O `id` não é enfeite do desempate: duas unidades podem ter a MESMA `identificacao` na mesma
     * carteira (nada no banco impede), e sem ele a comparação `>`/`<` pularia a gêmea ou entraria em
     * laço entre as duas.
     *
     * DUAS consultas de UMA linha cada (`setMaxResults(1)`, `SELECT o.id`), nunca a carteira inteira
     * carregada em memória para achar o vizinho — carteira real tem milhares de unidades. Escopo por
     * tenant explícito, além da carteira (defesa em profundidade).
     *
     * @return array{anteriorId: ?int, proximoId: ?int}
     */
    public function vizinhosNaCarteira(ObjetoCobranca $objeto): array
    {
        $carteira = $objeto->getCarteira();
        $tenant = $objeto->getTenant();
        $id = $objeto->getId();

        // Objeto ainda não persistido, sem carteira ou sem tenant não tem vizinhança definível — e
        // devolver as duas pontas nulas desabilita as duas setas, que é o comportamento honesto.
        if ($carteira === null || $tenant === null || $id === null) {
            return ['anteriorId' => null, 'proximoId' => null];
        }

        return [
            'anteriorId' => $this->vizinho($carteira, $tenant, $objeto->getIdentificacao(), $id, 'DESC'),
            'proximoId' => $this->vizinho($carteira, $tenant, $objeto->getIdentificacao(), $id, 'ASC'),
        ];
    }

    /**
     * Um lado da vizinhança. `ASC` procura o menor id/identificação MAIOR que o atual (o próximo);
     * `DESC` procura o maior MENOR que o atual (o anterior) — a comparação e a ordenação viram juntas,
     * senão a consulta devolveria a ponta da carteira em vez do vizinho.
     */
    private function vizinho(Carteira $carteira, Tenant $tenant, string $identificacao, int $id, string $direcao): ?int
    {
        $qb = $this->createQueryBuilder('o');
        $depoisDe = $direcao === 'ASC'
            ? static fn (string $campo, string $parametro) => $qb->expr()->gt($campo, $parametro)
            : static fn (string $campo, string $parametro) => $qb->expr()->lt($campo, $parametro);

        $resultado = $qb
            ->select('o.id')
            ->andWhere('o.carteira = :carteira')
            ->andWhere('o.tenant = :tenant')
            // `orX` (e não uma string com OR solto) para o parêntese existir de fato: sem ele o OR
            // escaparia dos filtros de carteira/tenant e a seta atravessaria escritórios.
            ->andWhere($qb->expr()->orX(
                $depoisDe('o.identificacao', ':identificacao'),
                $qb->expr()->andX(
                    $qb->expr()->eq('o.identificacao', ':identificacao'),
                    $depoisDe('o.id', ':id'),
                ),
            ))
            ->setParameter('carteira', $carteira)
            ->setParameter('tenant', $tenant)
            ->setParameter('identificacao', $identificacao)
            ->setParameter('id', $id)
            ->orderBy('o.identificacao', $direcao)
            ->addOrderBy('o.id', $direcao)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_SINGLE_SCALAR);

        return $resultado === null ? null : (int) $resultado;
    }

    /** Nº de objetos de cobrança da carteira (visão da carteira, Etapa 8). Escopo por tenant da carteira. */
    public function contarDaCarteira(Carteira $carteira): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('o.carteira = :carteira')
            ->andWhere('o.tenant = :tenant')
            ->setParameter('carteira', $carteira)
            ->setParameter('tenant', $carteira->getTenant())
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * As identificações de unidade da carteira, separadas por TEREM ou NÃO caso aberto — insumo dos
     * sub-baldes de "falta no sistema" da conferência (SPEC espelho §5.5).
     *
     * São três causas com três consertos diferentes: *unidade sem objeto* (cadastro nunca criado),
     * *objeto sem caso* (existe o imóvel, não a cobrança) e *caso existe, obrigação não* (a dívida
     * específica falta). Somá-las num balde só esconde qual é — e medido em produção há 22 objetos
     * sem caso nenhum, que cairiam no rótulo errado.
     *
     * Tenant explícito: roda em console, onde o `TenantFilter` não liga.
     *
     * @return array{comCaso: list<string>, semCaso: list<string>}
     */
    public function identificacoesPorSituacaoDeCaso(Carteira $carteira): array
    {
        /** @var list<array{identificacao: string, casos: int}> $linhas */
        $linhas = $this->createQueryBuilder('o')
            ->select('o.identificacao AS identificacao', 'COUNT(c.id) AS casos')
            // Tenant na CONDIÇÃO do join: um caso de outro escritório entraria no COUNT e faria a
            // unidade parecer ter cobrança aberta quando não tem.
            ->leftJoin(CasoCobranca::class, 'c', Join::ON, 'c.objeto = o AND c.tenant = :tenant')
            ->andWhere('o.carteira = :carteira')
            ->andWhere('o.tenant = :tenant')
            ->setParameter('carteira', $carteira)
            ->setParameter('tenant', $carteira->getTenant())
            ->groupBy('o.identificacao')
            ->getQuery()
            ->getResult();

        $comCaso = [];
        $semCaso = [];

        foreach ($linhas as $linha) {
            if ((int) $linha['casos'] > 0) {
                $comCaso[] = $linha['identificacao'];

                continue;
            }

            $semCaso[] = $linha['identificacao'];
        }

        return ['comCaso' => $comCaso, 'semCaso' => $semCaso];
    }
}
