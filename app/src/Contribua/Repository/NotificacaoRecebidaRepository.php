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
             VALUES (:origem, :evento, :tipo, :recurso, NOW())
             ON CONFLICT (origem, id_evento) DO NOTHING',
            ['origem' => $aviso->getOrigem(), 'evento' => $aviso->getIdEvento(), 'tipo' => $aviso->getTipo(), 'recurso' => $aviso->getIdRecurso()],
        );

        return $gravadas === 1;
    }

    public function marcarProcessada(string $origem, string $idEvento): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE contribua_notificacao_recebida SET processada_em = NOW() WHERE origem = :origem AND id_evento = :evento',
            ['origem' => $origem, 'evento' => $idEvento],
        );
    }
}
