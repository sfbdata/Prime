<?php

declare(strict_types=1);

namespace App\Contribua\Repository;

use App\Contribua\Entity\Campanha;
use App\Contribua\Entity\CampanhaParticipacao;
use App\Contribua\Enum\SituacaoDaParticipacao;
use App\Entity\Auth\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Inscrições da campanha. Tabela global (sem tenant): a consulta é sempre por campanha e, quando é da pessoa,
 * pelo usuário da sessão.
 *
 * @extends ServiceEntityRepository<CampanhaParticipacao>
 */
// Não-final: os testes de UseCase mockam o repositório.
class CampanhaParticipacaoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CampanhaParticipacao::class);
    }

    public function salvar(CampanhaParticipacao $participacao, bool $flush = false): void
    {
        $this->getEntityManager()->persist($participacao);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function daPessoa(Campanha $campanha, User $usuario): ?CampanhaParticipacao
    {
        return $this->findOneBy(['campanha' => $campanha, 'usuario' => $usuario]);
    }

    /** Todas as inscrições (inclusive aguardando pagamento): é o que conta para o limite de vagas. */
    public function contarInscricoes(Campanha $campanha): int
    {
        return (int) $this->createQueryBuilder('p')->select('COUNT(p.id)')->andWhere('p.campanha = :c')->setParameter('c', $campanha)->getQuery()->getSingleScalarResult();
    }

    /** As que já foram pagas: é o "N inscrito(s)" público. */
    public function contarPagas(Campanha $campanha): int
    {
        return (int) $this->createQueryBuilder('p')->select('COUNT(p.id)')->andWhere('p.campanha = :c')->andWhere('p.situacao <> :s')
            ->setParameter('c', $campanha)->setParameter('s', SituacaoDaParticipacao::AguardandoPagamento)->getQuery()->getSingleScalarResult();
    }

    public function cpfOuOabJaInscritos(Campanha $campanha, string $cpfHash, string $oab): bool
    {
        return (int) $this->createQueryBuilder('p')->select('COUNT(p.id)')->andWhere('p.campanha = :c')->andWhere('p.cpfHash = :h OR p.oab = :o')
            ->setParameter('c', $campanha)->setParameter('h', $cpfHash)->setParameter('o', $oab)->getQuery()->getSingleScalarResult() > 0;
    }

    /** @return list<CampanhaParticipacao> */
    public function aguardandoPagamento(Campanha $campanha): array
    {
        return $this->createQueryBuilder('p')->andWhere('p.campanha = :c')->andWhere('p.situacao = :s')
            ->setParameter('c', $campanha)->setParameter('s', SituacaoDaParticipacao::AguardandoPagamento)
            ->orderBy('p.criadaEm', 'ASC')->addOrderBy('p.id', 'ASC')->getQuery()->getResult();
    }
}
