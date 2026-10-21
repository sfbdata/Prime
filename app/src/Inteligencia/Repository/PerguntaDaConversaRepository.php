<?php

declare(strict_types=1);

namespace App\Inteligencia\Repository;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Entity\PerguntaDaConversa;
use App\Inteligencia\Enum\StatusDaAnalise;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * As perguntas da aba "Perguntar" (spec inteligencia-drawer-da-pasta, F3). Toda consulta recebe o
 * Tenant EXPLICITAMENTE e a conversa é sempre da pessoa (`autor`) na pasta: o worker roda sem
 * sessão (TenantFilter inerte) e ninguém vê a conversa de outra pessoa. Pergunta cuja análise foi
 * excluída (soft delete, rotas por id) some da conversa e do histórico.
 *
 * @extends ServiceEntityRepository<PerguntaDaConversa>
 */
// Não-final: o teste do UseCase o mocka (skill criar-repository).
class PerguntaDaConversaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PerguntaDaConversa::class);
    }

    public function salvar(PerguntaDaConversa $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * As últimas `$limite` perguntas da pessoa na pasta, em ordem cronológica (a mais antiga
     * primeiro, como a conversa é lida), com a análise já carregada.
     *
     * @return list<PerguntaDaConversa>
     */
    public function ultimasDaConversa(Tenant $tenant, int $pastaId, User $autor, int $limite): array
    {
        /** @var list<PerguntaDaConversa> $linhas */
        $linhas = $this->qbDaConversa($tenant, $pastaId, $autor)
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();

        return array_reverse($linhas);
    }

    /**
     * As últimas `$limite` perguntas CONCLUÍDAS da conversa anteriores a `$atual` (o histórico que
     * volta ao modelo), em ordem cronológica.
     *
     * @return list<PerguntaDaConversa>
     */
    public function concluidasAntesDe(PerguntaDaConversa $atual, Tenant $tenant, int $limite): array
    {
        /** @var list<PerguntaDaConversa> $linhas */
        $linhas = $this->qbDaConversa($tenant, $atual->getPastaId(), $atual->getAutor())
            ->andWhere('p.id < :atual')
            ->andWhere('a.status = :concluida')
            ->setParameter('atual', (int) $atual->getId())
            ->setParameter('concluida', StatusDaAnalise::Concluida)
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();

        return array_reverse($linhas);
    }

    /** A pergunta da pessoa na pasta que ainda espera resposta (pendente ou processando), se houver. */
    public function emAndamentoDaConversa(Tenant $tenant, int $pastaId, User $autor): ?PerguntaDaConversa
    {
        return $this->qbDaConversa($tenant, $pastaId, $autor)
            ->andWhere('a.status IN (:emAndamento)')
            ->setParameter('emAndamento', [StatusDaAnalise::Pendente, StatusDaAnalise::Processando])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** A pergunta de uma análise, no escritório informado (o worker chega pela análise). */
    public function daAnalise(AnaliseDeInteligencia $analise, Tenant $tenant): ?PerguntaDaConversa
    {
        return $this->findOneBy(['analise' => $analise, 'tenant' => $tenant]);
    }

    private function qbDaConversa(Tenant $tenant, int $pastaId, User $autor): QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->innerJoin('p.analise', 'a')
            ->addSelect('a')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('a.tenant = :tenant')
            ->andWhere('p.pastaId = :pastaId')
            ->andWhere('p.autor = :autor')
            ->andWhere('a.excluidaEm IS NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('pastaId', $pastaId)
            ->setParameter('autor', $autor)
            ->orderBy('p.criadaEm', 'DESC')
            ->addOrderBy('p.id', 'DESC');
    }
}
