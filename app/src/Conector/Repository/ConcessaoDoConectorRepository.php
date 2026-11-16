<?php

declare(strict_types=1);

namespace App\Conector\Repository;

use App\Conector\Entity\ConcessaoDoConector;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Enum\MotivoDaRevogacaoDaConcessao;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
}
