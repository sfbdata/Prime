<?php

declare(strict_types=1);

namespace App\Conector\Repository;

use App\Conector\Entity\ConcessaoDoConector;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Enum\MotivoDaRevogacaoDaConcessao;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Concessões (as cadeias de tokens). Revogar em lote vai pelo consentimento, que já é de uma pessoa num escritório.
 *
 * @extends ServiceEntityRepository<ConcessaoDoConector>
 */
class ConcessaoDoConectorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConcessaoDoConector::class);
    }

    public function salvar(ConcessaoDoConector $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Derruba todas as cadeias ainda abertas de um consentimento num UPDATE só (a pessoa revogou a conexão, ou a conta
     * ou o vínculo acabou). Não mexe na data nem no motivo de quem já tinha caído. Devolve quantas caíram agora.
     */
    public function revogarDoConsentimento(ConsentimentoDoConector $consentimento, MotivoDaRevogacaoDaConcessao $motivo, \DateTimeImmutable $agora): int
    {
        return (int) $this->getEntityManager()->createQuery(
            'UPDATE ' . ConcessaoDoConector::class . ' c SET c.revogadaEm = :agora, c.motivo = :motivo'
            . ' WHERE c.consentimento = :consentimento AND c.tenant = :tenant AND c.revogadaEm IS NULL'
        )
            ->setParameter('agora', $agora, 'datetime_immutable')
            ->setParameter('motivo', $motivo->value)
            ->setParameter('consentimento', $consentimento)
            ->setParameter('tenant', $consentimento->getTenant())
            ->execute();
    }

    /**
     * Faxina (13.2): apaga, num DELETE só e em todos os escritórios, as concessões vencidas ou revogadas antes de
     * `$limite` (quem chama passa "agora menos 30 dias"). O banco leva junto, pela cascata, os códigos e os tokens
     * delas. O consentimento (a conexão que a pessoa vê) fica. Devolve quantas saíram.
     */
    public function apagarEncerradasAntesDe(\DateTimeImmutable $limite): int
    {
        return (int) $this->getEntityManager()->createQuery(
            'DELETE FROM ' . ConcessaoDoConector::class . ' c WHERE c.expiraEm < :limite OR c.revogadaEm < :limite'
        )
            ->setParameter('limite', $limite, 'datetime_immutable')
            ->execute();
    }

    /**
     * Trava a linha da concessão (`SELECT ... FOR UPDATE`) até o fim da transação e a relê do banco (`HINT_REFRESH`).
     * Serializa as rotações de uma mesma cadeia (9.1): sem ela, a mãe reapresentada na janela de tolerância e a filha
     * girando ao mesmo tempo travam linhas diferentes, a mãe vê a filha ainda intocada e revoga o par que a filha acabou
     * de emitir, e sobram dois pares válidos. Ordem fixa para não haver impasse: primeiro a concessão, depois o token.
     * Só funciona dentro de uma transação aberta. Presa ao escritório da própria concessão.
     */
    public function travarParaGirar(ConcessaoDoConector $concessao): void
    {
        $this->createQueryBuilder('c')
            ->andWhere('c.id = :id')
            ->andWhere('c.tenant = :tenant')
            ->setParameter('id', $concessao->getId())
            ->setParameter('tenant', $concessao->getTenant())
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }
}
