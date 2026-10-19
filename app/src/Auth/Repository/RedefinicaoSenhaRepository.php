<?php

declare(strict_types=1);

namespace App\Auth\Repository;

use App\Auth\Entity\RedefinicaoSenha;
use App\Entity\Auth\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RedefinicaoSenha>
 */
class RedefinicaoSenhaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RedefinicaoSenha::class);
    }

    /**
     * Busca pelo token EM CLARO recebido na URL — o hash é calculado aqui, para que
     * nenhum chamador precise conhecer o algoritmo.
     */
    public function encontrarPorToken(string $token): ?RedefinicaoSenha
    {
        return $this->findOneBy(['tokenHash' => RedefinicaoSenha::hashDoToken($token)]);
    }

    /**
     * O pedido ainda não consumido mais novo do usuário (senha por código do desenho 10).
     *
     * O código de 6 números NÃO é chave de busca: com um milhão de valores possíveis, buscar
     * pelo hash deixaria o atacante testar códigos contra todas as contas de uma vez. Busca-se
     * o pedido da conta e compara-se o hash com hash_equals no UseCase, contando as tentativas.
     * A validade (15 minutos) é conferida pelo chamador.
     */
    public function encontrarPendenteMaisNovoDoUsuario(User $user): ?RedefinicaoSenha
    {
        return $this->createQueryBuilder('r')
            ->where('r.user = :user')
            ->andWhere('r.usadoEm IS NULL')
            ->setParameter('user', $user)
            ->orderBy('r.criadoEm', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Já existe linha com este hash (inclusive consumida, até a purga)? Serve para o código
     * novo não colidir com o índice único de `token_hash` quando o mesmo número sai de novo
     * para a mesma conta.
     */
    public function existeTokenHash(string $tokenHash): bool
    {
        return $this->count(['tokenHash' => $tokenHash]) > 0;
    }

    /**
     * Consome o pedido de forma atômica: o `WHERE usado_em IS NULL` faz o próprio banco
     * decidir quem chegou primeiro. Sem isso, dois POSTs simultâneos com o mesmo token
     * passariam os dois pela validação em PHP antes de qualquer um gravar.
     *
     * @return bool true se FOI ESTA chamada que consumiu o token
     */
    public function consumir(RedefinicaoSenha $redefinicao, \DateTimeImmutable $agora): bool
    {
        $linhas = (int) $this->createQueryBuilder('r')
            ->update()
            ->set('r.usadoEm', ':agora')
            ->where('r.id = :id')
            ->andWhere('r.usadoEm IS NULL')
            ->setParameter('agora', $agora)
            ->setParameter('id', $redefinicao->getId())
            ->getQuery()
            ->execute();

        return $linhas === 1;
    }

    /**
     * Reserva uma das tentativas do pedido no próprio banco, ANTES de comparar o código: o UPDATE condicional
     * decide quem ganha a vaga, então POSTs paralelos não comparam mais do que o máximo. Recarrega a entidade.
     *
     * @return bool true se esta chamada ganhou uma tentativa
     */
    public function reservarTentativa(RedefinicaoSenha $redefinicao, int $maximo): bool
    {
        $linhas = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE redefinicao_senha SET tentativas = tentativas + 1 WHERE id = :id AND usado_em IS NULL AND tentativas < :maximo',
            ['id' => $redefinicao->getId(), 'maximo' => $maximo],
        );
        $this->getEntityManager()->refresh($redefinicao);

        return $linhas === 1;
    }

    /** Código errado na última vaga: queima o pedido (usado_em), e nenhum código passa mais. */
    public function queimarSeEsgotado(RedefinicaoSenha $redefinicao, int $maximo, \DateTimeImmutable $agora): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE redefinicao_senha SET usado_em = :agora WHERE id = :id AND usado_em IS NULL AND tentativas >= :maximo',
            ['id' => $redefinicao->getId(), 'maximo' => $maximo, 'agora' => $agora->format('Y-m-d H:i:s')],
        );
        $this->getEntityManager()->refresh($redefinicao);
    }

    public function salvar(RedefinicaoSenha $redefinicao, bool $flush = false): void
    {
        $this->getEntityManager()->persist($redefinicao);

        if ($flush === true) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Invalida os pedidos ainda não consumidos do usuário (RS02: um token vivo por vez).
     * Apaga em vez de marcar usado — linha não consumida não tem valor de auditoria e
     * guarda IP + user agent.
     *
     * `$exceto` preserva a linha que está sendo consumida agora. Hoje é defesa em
     * profundidade: depois que `consumir()` passou a gravar `usado_em` de imediato, o
     * próprio `WHERE usado_em IS NULL` já preserva a linha corrente. A exceção continua
     * porque o chamador não deveria depender dessa ordem para não apagar o próprio pedido.
     *
     * @return int linhas removidas
     */
    public function invalidarPendentesDoUsuario(User $user, ?RedefinicaoSenha $exceto = null): int
    {
        $qb = $this->createQueryBuilder('r')
            ->delete()
            ->where('r.user = :user')
            ->andWhere('r.usadoEm IS NULL')
            ->setParameter('user', $user);

        if ($exceto?->getId() !== null) {
            $qb->andWhere('r.id != :exceto')->setParameter('exceto', $exceto->getId());
        }

        return (int) $qb->getQuery()->execute();
    }

    /**
     * Registros sem uso futuro que guardam PII (IP + user agent): os já consumidos e os
     * que expiraram sem uso. Espelha o critério do CadastroPendenteRepository.
     */
    public function contarPurgaveis(\DateTimeImmutable $agora): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.usadoEm IS NOT NULL OR r.expiraEm < :agora')
            ->setParameter('agora', $agora)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function purgar(\DateTimeImmutable $agora): int
    {
        return (int) $this->createQueryBuilder('r')
            ->delete()
            ->where('r.usadoEm IS NOT NULL OR r.expiraEm < :agora')
            ->setParameter('agora', $agora)
            ->getQuery()
            ->execute();
    }
}
