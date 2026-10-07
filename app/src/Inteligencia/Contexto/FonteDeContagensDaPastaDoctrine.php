<?php

declare(strict_types=1);

namespace App\Inteligencia\Contexto;

use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\OQueAIaSabeOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Implementação Doctrine da {@see FonteDeContagensDaPasta}: um `COUNT` por número, pasta E tenant
 * explícitos em cada um (não depende do TenantFilter estar ligado). O documento na lixeira não
 * conta: o `LixeiraFilter` já o esconde, e o `excluidoEm IS NULL` explícito garante o mesmo
 * número mesmo onde o filtro estiver desligado.
 */
final class FonteDeContagensDaPastaDoctrine implements FonteDeContagensDaPasta
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function contar(Tenant $tenant, Pasta $pasta): OQueAIaSabeOutput
    {
        $documentos = $this->contarDa(PastaDocumento::class, $tenant, $pasta, 'x.excluidoEm IS NULL');
        $tarefas = $this->contarDa(Tarefa::class, $tenant, $pasta);
        $prazos = (int) $this->em->createQueryBuilder()
            ->select('COUNT(x.id)')
            ->from(Tarefa::class, 'x')
            ->andWhere('x.pasta = :pasta')
            ->andWhere('x.tenant = :tenant')
            ->andWhere('x.prazo IS NOT NULL')
            ->andWhere('x.status != :concluida')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->setParameter('concluida', Tarefa::STATUS_CONCLUIDA)
            ->getQuery()
            ->getSingleScalarResult();
        $anotacoes = $this->contarDa(PastaMensagem::class, $tenant, $pasta)
            + $this->contarDa(PastaObservacaoDetalhes::class, $tenant, $pasta);

        return new OQueAIaSabeOutput($documentos, $tarefas, $prazos, $anotacoes);
    }

    /** @param class-string $classe */
    private function contarDa(string $classe, Tenant $tenant, Pasta $pasta, ?string $condicaoExtra = null): int
    {
        $qb = $this->em->createQueryBuilder()
            ->select('COUNT(x.id)')
            ->from($classe, 'x')
            ->andWhere('x.pasta = :pasta')
            ->andWhere('x.tenant = :tenant')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant);

        if ($condicaoExtra !== null) {
            $qb->andWhere($condicaoExtra);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
