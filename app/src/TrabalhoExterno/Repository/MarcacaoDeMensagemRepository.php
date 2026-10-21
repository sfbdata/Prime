<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Repository;

use App\Entity\Auth\User;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Entity\MarcacaoDeMensagem;
use App\TrabalhoExterno\Entity\MensagemDemanda;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MarcacaoDeMensagem> */
class MarcacaoDeMensagemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarcacaoDeMensagem::class);
    }

    public function salvar(MarcacaoDeMensagem $marcacao, bool $flush = false): void
    {
        $this->getEntityManager()->persist($marcacao);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @return array<int, MarcacaoDeMensagem> id da mensagem → marcação do usuário naquela demanda */
    public function daDemanda(DemandaExterna $demanda, User $usuario): array
    {
        $marcacoes = $this->createQueryBuilder('mm')
            ->join('mm.mensagem', 'm')
            ->andWhere('m.demanda = :demanda')
            ->andWhere('mm.usuario = :usuario')
            ->setParameter('demanda', $demanda)
            ->setParameter('usuario', $usuario)
            ->getQuery()
            ->getResult();

        $mapa = [];
        foreach ($marcacoes as $mm) {
            $mapa[(int) $mm->getMensagem()->getId()] = $mm;
        }

        return $mapa;
    }

    /** Mensagem apagada para todos deixa de ser favorita de todos (desenho l.1236, `x.fav = {}`). */
    public function tirarDasFavoritas(MensagemDemanda $mensagem): void
    {
        $this->createQueryBuilder('mm')
            ->update()
            ->set('mm.favorita', ':nao')
            ->andWhere('mm.mensagem = :mensagem')
            ->setParameter('nao', false)
            ->setParameter('mensagem', $mensagem)
            ->getQuery()
            ->execute();
    }
}
