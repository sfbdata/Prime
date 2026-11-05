<?php

declare(strict_types=1);

namespace App\ChatIa\Repository;

use App\ChatIa\Entity\AnexoDeChat;
use App\ChatIa\Entity\ConversaDeChat;
use App\ChatIa\Entity\MensagemDeChat;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Toda consulta filtra por escritório. O anexo é de quem o enviou: `soltosDoDono` exige escritório, dono e conversa.
 *
 * @extends ServiceEntityRepository<AnexoDeChat>
 */
class AnexoDeChatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnexoDeChat::class);
    }

    /**
     * Os anexos soltos (ainda sem pergunta) com esses ids, enviados por `$dono` para ESTA conversa. Id alheio, de outra
     * conversa ou já usado simplesmente não volta; quem chama compara as contagens.
     *
     * @param list<int> $ids
     *
     * @return list<AnexoDeChat>
     */
    public function soltosDoDono(array $ids, Tenant $tenant, User $dono, ConversaDeChat $conversa): array
    {
        if ($ids === []) {
            return [];
        }

        return array_values($this->createQueryBuilder('a')
            ->andWhere('a.id IN (:ids)')
            ->andWhere('a.tenant = :tenant')
            ->andWhere('a.usuario = :dono')
            ->andWhere('a.conversa = :conversa')
            ->andWhere('a.mensagem IS NULL')
            ->setParameter('ids', $ids)
            ->setParameter('tenant', $tenant)
            ->setParameter('dono', $dono)
            ->setParameter('conversa', $conversa)
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * Os anexos soltos que `$dono` já enviou para esta conversa (ainda sem pergunta).
     *
     * @return list<AnexoDeChat>
     */
    public function soltosDaConversa(ConversaDeChat $conversa, Tenant $tenant, User $dono): array
    {
        return array_values($this->createQueryBuilder('a')
            ->andWhere('a.conversa = :conversa')
            ->andWhere('a.tenant = :tenant')
            ->andWhere('a.usuario = :dono')
            ->andWhere('a.mensagem IS NULL')
            ->setParameter('conversa', $conversa)
            ->setParameter('tenant', $tenant)
            ->setParameter('dono', $dono)
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /** O anexo SOLTO de `$dono` (id + escritório + dono): o que ele pode descartar antes de enviar a pergunta. */
    public function soltoDoDono(int $id, Tenant $tenant, User $dono): ?AnexoDeChat
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.id = :id')
            ->andWhere('a.tenant = :tenant')
            ->andWhere('a.usuario = :dono')
            ->andWhere('a.mensagem IS NULL')
            ->setParameter('id', $id)
            ->setParameter('tenant', $tenant)
            ->setParameter('dono', $dono)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<AnexoDeChat> */
    public function daMensagem(MensagemDeChat $mensagem, Tenant $tenant): array
    {
        return array_values($this->createQueryBuilder('a')
            ->andWhere('a.mensagem = :mensagem')
            ->andWhere('a.tenant = :tenant')
            ->setParameter('mensagem', $mensagem)
            ->setParameter('tenant', $tenant)
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /** @return list<AnexoDeChat> todos da conversa (amarrados ou soltos) */
    public function daConversa(ConversaDeChat $conversa, Tenant $tenant): array
    {
        return array_values($this->createQueryBuilder('a')
            ->andWhere('a.conversa = :conversa')
            ->andWhere('a.tenant = :tenant')
            ->setParameter('conversa', $conversa)
            ->setParameter('tenant', $tenant)
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * Retenção ({@see \App\ChatIa\Service\ExpiracaoDoChatIa}): os anexos amarrados a falas criadas antes de `$limite`,
     * de TODOS os escritórios (rotina do agendador, sem sessão). Quem chama monta as chaves ANTES de apagar as linhas.
     *
     * @return list<AnexoDeChat>
     */
    public function dasMensagensAntesDe(\DateTimeImmutable $limite): array
    {
        return array_values($this->createQueryBuilder('a')
            ->join('a.mensagem', 'm')
            ->andWhere('m.criadaEm < :limite')
            ->setParameter('limite', $limite)
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /** Retenção: apaga as linhas dos anexos amarrados a falas criadas antes de `$limite`, de TODOS os escritórios. */
    public function apagarDasMensagensAntesDe(\DateTimeImmutable $limite): int
    {
        return (int) $this->createQueryBuilder('a')
            ->delete()
            ->andWhere(sprintf('a.mensagem IN (SELECT m.id FROM %s m WHERE m.criadaEm < :limite)', MensagemDeChat::class))
            ->setParameter('limite', $limite)
            ->getQuery()
            ->execute();
    }

    /**
     * Retenção: todos os anexos (amarrados ou soltos) das conversas `$conversaIds`, de qualquer escritório. Só para as
     * conversas que a retenção já travou ({@see ConversaDeChatRepository::travarVaziasEntre}).
     *
     * @param list<int> $conversaIds
     *
     * @return list<AnexoDeChat>
     */
    public function dasConversas(array $conversaIds): array
    {
        if ($conversaIds === []) {
            return [];
        }

        return array_values($this->createQueryBuilder('a')
            ->andWhere('a.conversa IN (:ids)')
            ->setParameter('ids', $conversaIds)
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    public function salvar(AnexoDeChat $anexo): void
    {
        $this->getEntityManager()->persist($anexo);
    }

    public function remover(AnexoDeChat $anexo): void
    {
        $this->getEntityManager()->remove($anexo);
    }
}
