<?php

declare(strict_types=1);

namespace App\ChatIa\Repository;

use App\ChatIa\Entity\ConversaDeChat;
use App\ChatIa\Entity\PropostaDeCadastro;
use App\ChatIa\Enum\EstadoDaProposta;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Toda consulta filtra por escritório E por dono: a proposta é de quem perguntou (a do colega, mesmo no mesmo
 * escritório, é "não encontrada").
 *
 * @extends ServiceEntityRepository<PropostaDeCadastro>
 */
class PropostaDeCadastroRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PropostaDeCadastro::class);
    }

    /** A proposta do dono (id + escritório + dono), ou nula. */
    public function doDono(int $id, Tenant $tenant, User $dono): ?PropostaDeCadastro
    {
        return $this->consultaDoDono($id, $tenant, $dono)->getQuery()->getOneOrNullResult();
    }

    /**
     * Como {@see self::doDono()}, com TRAVA DE LINHA (`SELECT ... FOR UPDATE`): só vale dentro de transação. Dois cliques
     * seguidos esperam um no outro, e o segundo já enxerga a proposta decidida.
     */
    public function doDonoComTrava(int $id, Tenant $tenant, User $dono): ?PropostaDeCadastro
    {
        // HINT_REFRESH: a linha pode já estar no gerenciador de identidades (lida antes da trava), e o estado que vale é o do banco.
        return $this->consultaDoDono($id, $tenant, $dono)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    /** @return list<PropostaDeCadastro> as propostas desta conversa, na ordem em que nasceram */
    public function daConversa(ConversaDeChat $conversa, Tenant $tenant): array
    {
        return array_values($this->createQueryBuilder('p')
            ->andWhere('p.conversa = :conversa')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('conversa', $conversa)
            ->setParameter('tenant', $tenant)
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * Dos ids de mensagens dados, os que trouxeram alguma proposta (qualquer estado): o histórico do pedido marca essas falas
     * com "proposta registrada", sem nenhum dado da proposta.
     *
     * @param list<int> $mensagemIds
     *
     * @return list<int>
     */
    public function mensagensComProposta(array $mensagemIds, Tenant $tenant): array
    {
        if ($mensagemIds === []) {
            return [];
        }
        $linhas = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.mensagem) AS mensagemId')
            ->distinct()
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.mensagem IN (:ids)')
            ->setParameter('tenant', $tenant)
            ->setParameter('ids', $mensagemIds)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('intval', $linhas);
    }

    /**
     * Zera os dados pessoais das propostas pendentes que já venceram (viram `expirada`). Em lote, sem passar pela
     * auditoria: é o fim do prazo, não um ato de alguém.
     */
    public function expirarVencidas(Tenant $tenant, \DateTimeImmutable $agora): int
    {
        return (int) $this->getEntityManager()->createQuery(
            'UPDATE ' . PropostaDeCadastro::class . ' p SET p.estado = :expirada, p.dados = NULL, p.decididaEm = :agora
             WHERE p.tenant = :tenant AND p.estado = :pendente AND p.expiraEm <= :agora',
        )
            ->setParameter('expirada', EstadoDaProposta::Expirada)
            ->setParameter('pendente', EstadoDaProposta::Pendente)
            ->setParameter('tenant', $tenant)
            ->setParameter('agora', $agora)
            ->execute();
    }

    public function salvar(PropostaDeCadastro $proposta): void
    {
        $this->getEntityManager()->persist($proposta);
    }

    private function consultaDoDono(int $id, Tenant $tenant, User $dono): QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.id = :id')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.usuario = :dono')
            ->setParameter('id', $id)
            ->setParameter('tenant', $tenant)
            ->setParameter('dono', $dono);
    }
}
