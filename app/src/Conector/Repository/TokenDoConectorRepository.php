<?php

declare(strict_types=1);

namespace App\Conector\Repository;

use App\Conector\Entity\TokenDoConector;
use App\Conector\Enum\TipoDoTokenDoConector;
use Doctrine\DBAL\LockMode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Tokens opacos do conector (acesso e renovação). A busca pelo hash não recebe escritório: no `/mcp` e no
 * `/oauth/token` é o token que diz o escritório, e o portão (PR 1) confere vínculo, escritório e consentimento logo
 * depois.
 *
 * @extends ServiceEntityRepository<TokenDoConector>
 */
class TokenDoConectorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TokenDoConector::class);
    }

    public function salvar(TokenDoConector $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Busca sem escritório de propósito: no `/oauth/token` e no `/mcp` não há sessão, e o escritório só é conhecido
     * depois de achar a linha (é ela que o revela). O hash é SHA-256 de 256 bits aleatórios, único na tabela, então
     * não há colisão entre escritórios; quem chama confere tipo, validade e escritório da linha achada.
     */
    public function buscarPorIdentificadorHash(string $identificadorHash): ?TokenDoConector
    {
        return $this->findOneBy(['identificadorHash' => $identificadorHash]);
    }

    /**
     * A renovação a girar, com a linha travada (`SELECT ... FOR UPDATE`) até o fim da transação: duas rotações
     * simultâneas da mesma renovação não passam as duas pelo `usado_em` nulo (9.1). Só funciona dentro de uma transação
     * aberta (sem ela o Doctrine lança `TransactionRequiredException`). Sem escritório pelo mesmo motivo de
     * {@see buscarPorIdentificadorHash()}; só devolve token do tipo renovação.
     */
    public function buscarRenovacaoParaGirar(string $identificadorHash): ?TokenDoConector
    {
        $renovacao = $this->createQueryBuilder('t')
            ->andWhere('t.identificadorHash = :hash')
            ->andWhere('t.tipo = :tipo')
            ->setParameter('hash', $identificadorHash)
            ->setParameter('tipo', TipoDoTokenDoConector::Renovacao->value)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        return $renovacao instanceof TokenDoConector ? $renovacao : null;
    }

    /**
     * A renovação mais recente gerada por esta (a "filha" que {@see TokenDoConector::girar()} recebe), presa ao
     * escritório dela; nula se esta nunca girou.
     */
    public function buscarRenovacaoGeradaPor(TokenDoConector $renovacao): ?TokenDoConector
    {
        $filha = $this->createQueryBuilder('t')
            ->andWhere('t.geradoPor = :renovacao')
            ->andWhere('t.tenant = :tenant')
            ->andWhere('t.tipo = :tipo')
            ->setParameter('renovacao', $renovacao)
            ->setParameter('tenant', $renovacao->getTenant())
            ->setParameter('tipo', TipoDoTokenDoConector::Renovacao->value)
            ->orderBy('t.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $filha instanceof TokenDoConector ? $filha : null;
    }

    /**
     * Repetição da rotação dentro da janela (9.1): revoga o par que esta renovação gerou antes, num UPDATE só, preso ao
     * escritório dela. Devolve quantos caíram agora.
     */
    public function revogarParGeradoPor(TokenDoConector $renovacao, \DateTimeImmutable $agora): int
    {
        return (int) $this->getEntityManager()->createQuery(
            'UPDATE ' . TokenDoConector::class . ' t SET t.revogadoEm = :agora'
            . ' WHERE t.geradoPor = :renovacao AND t.tenant = :tenant AND t.revogadoEm IS NULL'
        )
            ->setParameter('agora', $agora, 'datetime_immutable')
            ->setParameter('renovacao', $renovacao)
            ->setParameter('tenant', $renovacao->getTenant())
            ->execute();
    }

    /**
     * Faxina (13.2): apaga, num DELETE só e em todos os escritórios, os tokens (acesso e renovação) que venceram antes de
     * `$limite` (quem chama passa "agora menos 1 dia"). A renovação vale até o fim da concessão, então nenhuma renovação
     * de cadeia viva sai daqui, nem a já girada que a janela de tolerância ainda consulta. O `gerado_por_id` de quem
     * fica aponta para nulo (`ON DELETE SET NULL`). Devolve quantos saíram.
     */
    public function apagarVencidosAntesDe(\DateTimeImmutable $limite): int
    {
        return (int) $this->getEntityManager()->createQuery(
            'DELETE FROM ' . TokenDoConector::class . ' t WHERE t.expiraEm < :limite'
        )
            ->setParameter('limite', $limite, 'datetime_immutable')
            ->execute();
    }
}
