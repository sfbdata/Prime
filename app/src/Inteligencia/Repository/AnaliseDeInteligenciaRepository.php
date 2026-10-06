<?php

declare(strict_types=1);

namespace App\Inteligencia\Repository;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Enum\StatusDaAnalise;
use App\Inteligencia\Enum\TipoDeAnalise;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Toda consulta recebe o Tenant EXPLICITAMENTE: o worker e o console rodam sem sessão, o
 * TenantFilter fica inerte lá, e quem segura o isolamento é este repositório sozinho.
 *
 * As consultas "por alvo" são também por TIPO (e por agente, quando houver): a mesma pasta tem
 * análises do Push (`resumo_push`) e dos agentes (`analise_pasta`, uma trilha por agente), e uma
 * pendente de um agente não pode travar o pedido do Push nem virar "análise anterior" dele. O
 * padrão `ResumoPush` mantém os chamadores da fatia 1 como estavam.
 *
 * @extends ServiceEntityRepository<AnaliseDeInteligencia>
 */
// Não-final: espelha PublicacaoDjenRepository e permite substituição por mock nos testes de UseCase.
class AnaliseDeInteligenciaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnaliseDeInteligencia::class);
    }

    public function salvar(AnaliseDeInteligencia $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * O EntityManager ainda aceita escrita? Depois de uma exceção do banco (o transport `doctrine`
     * divide a conexão com a aplicação) ele fecha, e um flush em EM fechado lança de novo — quem
     * trata o erro precisa perguntar antes de tentar registrar a falha.
     */
    public function emAberto(): bool
    {
        return $this->getEntityManager()->isOpen();
    }

    /**
     * Análises de um alvo (pasta) e tipo, mais recente primeiro, sem as excluídas. Com `agente`
     * nulo e tipo `AnalisePasta`, devolve as de TODOS os agentes (painel do drawer).
     *
     * @return AnaliseDeInteligencia[]
     */
    public function listarPorAlvo(
        Tenant $tenant,
        string $alvoTipo,
        int $alvoId,
        int $limite = 20,
        TipoDeAnalise $tipo = TipoDeAnalise::ResumoPush,
        ?Agente $agente = null,
    ): array {
        return $this->qbDoAlvo($tenant, $alvoTipo, $alvoId, $tipo, $agente)
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /** A análise em andamento (pendente ou processando) do alvo/tipo/agente, se houver — idempotência do pedido. */
    public function findPendenteDoAlvo(
        Tenant $tenant,
        string $alvoTipo,
        int $alvoId,
        TipoDeAnalise $tipo = TipoDeAnalise::ResumoPush,
        ?Agente $agente = null,
    ): ?AnaliseDeInteligencia {
        return $this->qbDoAlvo($tenant, $alvoTipo, $alvoId, $tipo, $agente)
            ->andWhere('a.status IN (:emAndamento)')
            ->setParameter('emAndamento', [StatusDaAnalise::Pendente, StatusDaAnalise::Processando])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** A última concluída do alvo/tipo/agente — base do "nada novo desde a última" e do [NOVA] do prompt. */
    public function findUltimaConcluidaDoAlvo(
        Tenant $tenant,
        string $alvoTipo,
        int $alvoId,
        TipoDeAnalise $tipo = TipoDeAnalise::ResumoPush,
        ?Agente $agente = null,
    ): ?AnaliseDeInteligencia {
        return $this->qbDoAlvo($tenant, $alvoTipo, $alvoId, $tipo, $agente)
            ->andWhere('a.status = :concluida')
            ->setParameter('concluida', StatusDaAnalise::Concluida)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Busca tenant-safe por id — find() por PK não passa pelo filtro. */
    public function findOneDoTenant(int $id, Tenant $tenant): ?AnaliseDeInteligencia
    {
        return $this->findOneBy(['id' => $id, 'tenant' => $tenant]);
    }

    /**
     * Guarda de IDOR das ações por id: a análise tem de ser do escritório E do alvo da URL. A
     * restrição fica na consulta, não numa comparação depois.
     */
    public function findOneDoTenantEAlvo(int $id, Tenant $tenant, string $alvoTipo, int $alvoId): ?AnaliseDeInteligencia
    {
        return $this->findOneBy(['id' => $id, 'tenant' => $tenant, 'alvoTipo' => $alvoTipo, 'alvoId' => $alvoId]);
    }

    /**
     * Quantas análises o escritório pediu desde um instante (cota diária/mensal). Conta TODAS —
     * inclusive falhas e excluídas: a cota mede o que foi pedido, e a exclusão é só visual.
     */
    public function contarDesde(Tenant $tenant, \DateTimeImmutable $desde): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.tenant = :tenant')
            ->andWhere('a.criadaEm >= :desde')
            ->setParameter('tenant', $tenant)
            ->setParameter('desde', $desde)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Contagem por escritório e status, para o comando de status (CLI, sem TenantFilter — lista
     * todos os tenants de propósito).
     *
     * @return list<array{tenantId: int, tenantNome: string, status: string, total: int}>
     */
    public function contarPorTenantEStatus(): array
    {
        $linhas = $this->createQueryBuilder('a')
            ->select('IDENTITY(a.tenant) AS tenantId', 't.name AS tenantNome', 'a.status AS status', 'COUNT(a.id) AS total')
            ->join('a.tenant', 't')
            ->groupBy('a.tenant', 't.name', 'a.status')
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static function (array $linha): array {
            $status = $linha['status'];

            return [
                'tenantId' => (int) $linha['tenantId'],
                'tenantNome' => (string) $linha['tenantNome'],
                'status' => $status instanceof StatusDaAnalise ? $status->value : (string) $status,
                'total' => (int) $linha['total'],
            ];
        }, $linhas);
    }

    private function qbDoAlvo(Tenant $tenant, string $alvoTipo, int $alvoId, TipoDeAnalise $tipo, ?Agente $agente): QueryBuilder
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.tenant = :tenant')
            ->andWhere('a.alvoTipo = :alvoTipo')
            ->andWhere('a.alvoId = :alvoId')
            ->andWhere('a.tipo = :tipo')
            ->andWhere('a.excluidaEm IS NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('alvoTipo', $alvoTipo)
            ->setParameter('alvoId', $alvoId)
            ->setParameter('tipo', $tipo)
            ->orderBy('a.criadaEm', 'DESC')
            ->addOrderBy('a.id', 'DESC');

        if ($agente !== null) {
            $qb->andWhere('a.agente = :agente')->setParameter('agente', $agente);
        }

        return $qb;
    }
}
