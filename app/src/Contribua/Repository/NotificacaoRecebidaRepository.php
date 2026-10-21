<?php

declare(strict_types=1);

namespace App\Contribua\Repository;

use App\Contribua\Entity\NotificacaoRecebida;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NotificacaoRecebida>
 */
// Não-final: os testes de UseCase mockam o repositório.
class NotificacaoRecebidaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificacaoRecebida::class);
    }

    /**
     * Grava o aviso se ele é novo. Devolve false quando o mesmo `(origem, idEvento)` já chegou antes
     * (repetição do gateway): quem chama responde 200 e não processa de novo.
     *
     * INSERT ... ON CONFLICT DO NOTHING e não "consultar e depois gravar": duas entregas simultâneas do
     * mesmo evento não podem passar as duas.
     */
    public function registrarSeNova(NotificacaoRecebida $aviso): bool
    {
        $gravadas = $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO contribua_notificacao_recebida (origem, id_evento, tipo, id_recurso, recebida_em)
             VALUES (:origem, :evento, :tipo, :recurso, :agora)
             ON CONFLICT (origem, id_evento) DO NOTHING',
            ['origem' => $aviso->getOrigem(), 'evento' => $aviso->getIdEvento(), 'tipo' => $aviso->getTipo(), 'recurso' => $aviso->getIdRecurso(), 'agora' => self::agora()],
        );

        return $gravadas === 1;
    }

    public function marcarProcessada(string $origem, string $idEvento): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE contribua_notificacao_recebida SET processada_em = :agora WHERE origem = :origem AND id_evento = :evento',
            ['origem' => $origem, 'evento' => $idEvento, 'agora' => self::agora()],
        );
    }

    /**
     * Avisos gravados e ainda não processados (o gateway estava fora do ar na reconsulta), das origens
     * dadas, do mais antigo para o mais novo.
     *
     * @param list<string> $origens
     *
     * @return list<NotificacaoRecebida>
     */
    public function pendentes(array $origens, int $limite = 200): array
    {
        if ($origens === []) {
            return [];
        }

        return $this->createQueryBuilder('n')
            ->andWhere('n.processadaEm IS NULL')
            ->andWhere('n.origem IN (:origens)')->setParameter('origens', $origens)
            ->orderBy('n.id', 'ASC')
            ->setMaxResults($limite)
            ->getQuery()->getResult();
    }

    /** A hora do PHP (America/Sao_Paulo), como o resto do sistema grava: o NOW() do Postgres está em UTC. */
    private static function agora(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
