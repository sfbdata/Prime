<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Repository;

use App\Entity\Auth\User;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Entity\LeituraDemanda;
use App\TrabalhoExterno\Entity\MensagemDemanda;
use App\TrabalhoExterno\Enum\TipoMensagem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MensagemDemanda> */
class MensagemDemandaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MensagemDemanda::class);
    }

    /**
     * Não lidas por demanda, numa consulta só (a lista mostra o número na coluna CHAT). Conta as
     * mensagens de OUTRAS pessoas depois da última lida; notas internas só contam para quem é da
     * Central. As demandas já vêm escopadas por quem chama.
     *
     * @param list<DemandaExterna> $demandas
     *
     * @return array<int, int> id da demanda → não lidas (ausente = 0)
     */
    public function naoLidasPorDemanda(array $demandas, User $usuario, bool $interno): array
    {
        if ($demandas === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.demanda) AS demanda', 'COUNT(m.id) AS total')
            ->leftJoin(LeituraDemanda::class, 'l', 'WITH', 'l.demanda = m.demanda AND l.usuario = :usuario')
            ->andWhere('m.demanda IN (:demandas)')
            ->andWhere('m.autor IS NULL OR m.autor <> :usuario')
            ->andWhere('m.id > COALESCE(l.ultimaMensagemLida, 0)')
            ->setParameter('usuario', $usuario)
            ->setParameter('demandas', $demandas)
            ->groupBy('m.demanda');

        if (!$interno) {
            $qb->andWhere('m.tipo <> :interna')->setParameter('interna', TipoMensagem::Interna);
        }

        $mapa = [];
        foreach ($qb->getQuery()->getArrayResult() as $linha) {
            $mapa[(int) $linha['demanda']] = (int) $linha['total'];
        }

        return $mapa;
    }
}
