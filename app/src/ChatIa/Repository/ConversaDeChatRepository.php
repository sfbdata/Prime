<?php

declare(strict_types=1);

namespace App\ChatIa\Repository;

use App\ChatIa\Entity\ConversaDeChat;
use App\ChatIa\Enum\OrigemDaConversa;
use App\ChatIa\Entity\MensagemDeChat;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Toda consulta filtra por escritório E por dono: o `TenantFilter` é só a segunda barreira, e a conversa é particular
 * (a do colega, mesmo no mesmo escritório, é "não encontrada").
 *
 * @extends ServiceEntityRepository<ConversaDeChat>
 */
class ConversaDeChatRepository extends ServiceEntityRepository
{
    /** Teto da lista da barra lateral; a busca por texto é quem alcança as mais antigas. */
    public const LIMITE_DA_LISTA = 300;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConversaDeChat::class);
    }

    public function doDono(int $id, Tenant $tenant, User $dono): ?ConversaDeChat
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.id = :id')
            ->andWhere('c.tenant = :tenant')
            ->andWhere('c.usuario = :dono')
            ->setParameter('id', $id)
            ->setParameter('tenant', $tenant)
            ->setParameter('dono', $dono)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** A conversa importada do histórico da Central, se esta pessoa já a tem (no máximo uma). */
    public function importadaDoDono(Tenant $tenant, User $dono): ?ConversaDeChat
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenant')
            ->andWhere('c.usuario = :dono')
            ->andWhere('c.origem = :origem')
            ->setParameter('tenant', $tenant)
            ->setParameter('dono', $dono)
            ->setParameter('origem', OrigemDaConversa::Importada)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * As conversas do dono: fixadas primeiro, depois as mais recentes. `$busca` casa com o título ou com o texto de
     * alguma fala (sem diferenciar caixa).
     *
     * @return list<ConversaDeChat>
     */
    public function listarDoDono(Tenant $tenant, User $dono, ?string $busca = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenant')
            ->andWhere('c.usuario = :dono')
            ->setParameter('tenant', $tenant)
            ->setParameter('dono', $dono)
            ->orderBy('c.fixada', 'DESC')
            ->addOrderBy('c.atualizadaEm', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults(self::LIMITE_DA_LISTA);

        $busca = trim((string) $busca);
        if ($busca !== '') {
            $qb->andWhere(sprintf(
                'LOWER(COALESCE(c.titulo, \'\')) LIKE :busca OR EXISTS (SELECT 1 FROM %s m WHERE m.conversa = c AND m.tenant = :tenant AND LOWER(m.conteudo) LIKE :busca)',
                MensagemDeChat::class,
            ))->setParameter('busca', '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($busca)) . '%');
        }

        return array_values($qb->getQuery()->getResult());
    }

    public function contarVaziasDoDono(Tenant $tenant, User $dono): int
    {
        return (int) $this->vaziasDoDono($tenant, $dono)->select('COUNT(c.id)')->getQuery()->getSingleScalarResult();
    }

    /** A conversa SEM nenhuma mensagem mais recente do dono, ou nulo. */
    public function vaziaMaisRecenteDoDono(Tenant $tenant, User $dono): ?ConversaDeChat
    {
        return $this->vaziasDoDono($tenant, $dono)->orderBy('c.id', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    private function vaziasDoDono(Tenant $tenant, User $dono): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenant')
            ->andWhere('c.usuario = :dono')
            ->andWhere(sprintf('NOT EXISTS (SELECT 1 FROM %s m WHERE m.conversa = c AND m.tenant = :tenant)', MensagemDeChat::class))
            ->setParameter('tenant', $tenant)
            ->setParameter('dono', $dono);
    }

    /**
     * Retenção ({@see \App\ChatIa\Service\ExpiracaoDoChatIa}): trava (`FOR UPDATE`, em ordem de id) as conversas `$ids`
     * e devolve as que estão sem fala nenhuma. A conferência vem DEPOIS da trava, então enxerga a fala confirmada antes
     * dela; e fala ou anexo novo nessas conversas espera o fim da transação. Exige transação aberta. Varre qualquer
     * escritório (rotina do agendador, sem sessão).
     *
     * @param list<int> $ids
     *
     * @return list<int>
     */
    public function travarVaziasEntre(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $this->createQueryBuilder('c')
            ->select('c.id')
            ->andWhere('c.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('c.id', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getScalarResult();

        return array_map('intval', $this->createQueryBuilder('c')
            ->select('c.id')
            ->andWhere('c.id IN (:ids)')
            ->andWhere(sprintf('NOT EXISTS (SELECT 1 FROM %s m WHERE m.conversa = c)', MensagemDeChat::class))
            ->setParameter('ids', $ids)
            ->orderBy('c.id', 'ASC')
            ->getQuery()
            ->getSingleColumnResult());
    }

    /**
     * Retenção: apaga as conversas que {@see travarVaziasEntre} devolveu, na MESMA transação (sem conferir de novo: as
     * chaves dos anexos soltos já foram montadas para exatamente estas). O banco leva em cascata os anexos soltos e as
     * propostas delas. Devolve quantas apagou.
     *
     * @param list<int> $ids
     */
    public function apagarVaziasTravadas(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return (int) $this->createQueryBuilder('c')
            ->delete()
            ->andWhere('c.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }

    public function salvar(ConversaDeChat $conversa): void
    {
        $this->getEntityManager()->persist($conversa);
    }

    public function remover(ConversaDeChat $conversa): void
    {
        $this->getEntityManager()->remove($conversa);
    }
}
