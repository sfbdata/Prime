<?php

declare(strict_types=1);

namespace App\Agenda\Repository;

use App\Agenda\Entity\ConexaoGoogleAgenda;
use App\Agenda\Entity\Evento;
use App\Agenda\Entity\LigacaoComGoogle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Ligações dos compromissos com os eventos na agenda do escritório no Google de cada pessoa. Roda no worker,
 * onde o filtro de escritório não age: toda consulta vai pela conexão (que já é de um escritório).
 *
 * @extends ServiceEntityRepository<LigacaoComGoogle>
 */
class LigacaoComGoogleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LigacaoComGoogle::class);
    }

    public function daConexaoPeloGoogle(ConexaoGoogleAgenda $conexao, string $googleId): ?LigacaoComGoogle
    {
        return $this->findOneBy(['conexao' => $conexao, 'googleId' => $googleId]);
    }

    public function daConexaoPeloEvento(ConexaoGoogleAgenda $conexao, Evento $evento): ?LigacaoComGoogle
    {
        return $this->findOneBy(['conexao' => $conexao, 'evento' => $evento]);
    }

    /**
     * Cópias de compromissos de outra pessoa em que a dona desta conexão deixou de participar (ou que viraram
     * "Só eu"): saem da agenda dela no Google.
     *
     * @return list<LigacaoComGoogle>
     */
    public function copiasQueNaoValemMais(ConexaoGoogleAgenda $conexao): array
    {
        return $this->createQueryBuilder('l')
            ->join('l.evento', 'e')
            ->andWhere('l.conexao = :conexao')
            ->andWhere('e.criador <> :pessoa')
            ->andWhere(':pessoa NOT MEMBER OF e.participantes OR e.visibilidade = :soEu')
            ->setParameter('conexao', $conexao)
            ->setParameter('pessoa', $conexao->getUsuario())
            ->setParameter('soEu', Evento::VISIBILIDADE_SOMENTE_EU)
            ->getQuery()
            ->getResult();
    }

    /** Outra conta ou agenda nova: as ligações antigas não valem (os compromissos vão de novo, como novos). */
    public function esquecerDaConexao(ConexaoGoogleAgenda $conexao): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM agenda_google_ligacao WHERE conexao_id = :c',
            ['c' => $conexao->getId()],
        );
    }

    public function salvar(LigacaoComGoogle $ligacao): void
    {
        $this->getEntityManager()->persist($ligacao);
    }

    public function remover(LigacaoComGoogle $ligacao): void
    {
        $this->getEntityManager()->remove($ligacao);
    }
}
