<?php

declare(strict_types=1);

namespace App\Conector\Repository;

use App\Conector\Entity\TokenDoConector;
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

    public function buscarPorIdentificadorHash(string $identificadorHash): ?TokenDoConector
    {
        return $this->findOneBy(['identificadorHash' => $identificadorHash]);
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
}
