<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\UltimoAlertaDaMetaOutput;
use App\Pasta\Entity\Pasta;
use App\Tarefa\UseCase\AlertarResponsavelDaMetaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Último alerta "Alertar para verificar" de cada meta da pasta, para o sino da aba
 * Metas mostrar "Alertado: Nome às HH:MM".
 *
 * UMA consulta por pasta (nunca uma por linha). Isolamento: a notificação E a meta
 * têm de ser do escritório informado — cláusula explícita, além do TenantFilter —, e
 * a meta tem de ser desta pasta. Só o tipo gravado pelo sino conta; as outras
 * notificações da meta (criada, concluída…) não acendem o sino.
 */
final class UltimosAlertasDasMetas
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array<int, UltimoAlertaDaMetaOutput> id da meta => último alerta
     */
    public function daPasta(Pasta $pasta, Tenant $tenant): array
    {
        if ($pasta->getId() === null || $tenant->getId() === null) {
            return [];
        }

        $linhas = $this->em->createQueryBuilder()
            ->select('IDENTITY(n.tarefa) AS tarefaId', 'n.criadaEm AS criadaEm', 'u.fullName AS nome')
            ->from(Notificacao::class, 'n')
            ->join('n.tarefa', 't')
            ->join('n.usuario', 'u')
            ->where('t.pasta = :pasta')
            ->andWhere('t.tenant = :tenant')
            ->andWhere('n.tenant = :tenant')
            ->andWhere('n.tipo = :tipo')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->setParameter('tipo', AlertarResponsavelDaMetaUseCase::TIPO_NOTIFICACAO)
            ->orderBy('n.criadaEm', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->getQuery()
            ->getArrayResult();

        $agora   = $this->clock->now();
        $ultimos = [];
        foreach ($linhas as $linha) {
            $tarefaId = (int) $linha['tarefaId'];
            if (isset($ultimos[$tarefaId])) {
                continue; // a ordem é decrescente: o primeiro de cada meta é o último alerta
            }
            $ultimos[$tarefaId] = UltimoAlertaDaMetaOutput::de($linha['nome'], $linha['criadaEm'], $agora);
        }

        return $ultimos;
    }
}
