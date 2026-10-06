<?php

declare(strict_types=1);

namespace App\Inteligencia\Contexto;

use App\Cliente\Entity\Cliente;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaChecklistItem;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Pasta\Entity\PastaPagamento;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Implementação Doctrine da {@see FonteDeDadosDaPasta}. Pasta E tenant explícitos em TODA
 * consulta: este código roda no worker, onde o TenantFilter é inerte, e uma entidade filha com
 * tenant trocado (não deveria existir) nunca é lida.
 */
final class FonteDeDadosDaPastaDoctrine implements FonteDeDadosDaPasta
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function clientesDaPasta(Tenant $tenant, Pasta $pasta): array
    {
        // A relação é ManyToMany com dono na Pasta e sem lado inverso no Cliente: o vínculo entra
        // por subconsulta (o DQL não deixa selecionar só o alias da junção), e o tenant é exigido
        // nas duas pontas.
        return $this->em->createQueryBuilder()
            ->select('c')
            ->from(Cliente::class, 'c')
            ->andWhere('c.tenant = :tenant')
            ->andWhere('c.id IN (SELECT c2.id FROM ' . Pasta::class . ' p JOIN p.clientes c2 WHERE p = :pasta AND p.tenant = :tenant)')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->orderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function tarefasDaPasta(Tenant $tenant, Pasta $pasta, int $limite): array
    {
        $abertas = $this->em->createQueryBuilder()
            ->select('t')
            ->from(Tarefa::class, 't')
            ->andWhere('t.pasta = :pasta')
            ->andWhere('t.tenant = :tenant')
            ->andWhere('t.status != :concluida')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->setParameter('concluida', Tarefa::STATUS_CONCLUIDA)
            ->orderBy('t.prazo', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();

        $restante = $limite - count($abertas);
        if ($restante <= 0) {
            return $abertas;
        }

        $concluidas = $this->em->createQueryBuilder()
            ->select('t')
            ->from(Tarefa::class, 't')
            ->andWhere('t.pasta = :pasta')
            ->andWhere('t.tenant = :tenant')
            ->andWhere('t.status = :concluida')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->setParameter('concluida', Tarefa::STATUS_CONCLUIDA)
            ->orderBy('t.dataConclusao', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setMaxResults($restante)
            ->getQuery()
            ->getResult();

        return array_merge($abertas, $concluidas);
    }

    public function anotacoesDaPasta(Tenant $tenant, Pasta $pasta, int $limite): array
    {
        return $this->daPasta(PastaMensagem::class, $tenant, $pasta, $limite, 'criadaEm', 'DESC');
    }

    public function observacoesDaPasta(Tenant $tenant, Pasta $pasta, int $limite): array
    {
        return $this->daPasta(PastaObservacaoDetalhes::class, $tenant, $pasta, $limite, 'criadaEm', 'DESC');
    }

    public function documentosDaPasta(Tenant $tenant, Pasta $pasta, int $limite): array
    {
        return $this->daPasta(PastaDocumento::class, $tenant, $pasta, $limite, 'carregadoEm', 'DESC');
    }

    public function checklistDaPasta(Tenant $tenant, Pasta $pasta, int $limite): array
    {
        return $this->daPasta(PastaChecklistItem::class, $tenant, $pasta, $limite, 'ordem', 'ASC');
    }

    public function pagamentosDaPasta(Tenant $tenant, Pasta $pasta, int $limite): array
    {
        return $this->daPasta(PastaPagamento::class, $tenant, $pasta, $limite, 'vencimento', 'DESC');
    }

    public function observacoesFinanceirasDaPasta(Tenant $tenant, Pasta $pasta, int $limite): array
    {
        return $this->daPasta(PastaObservacaoFinanceira::class, $tenant, $pasta, $limite, 'criadaEm', 'DESC');
    }

    /**
     * @template T of object
     * @param class-string<T> $classe
     * @return list<T>
     */
    private function daPasta(string $classe, Tenant $tenant, Pasta $pasta, int $limite, string $ordem, string $sentido): array
    {
        return $this->em->createQueryBuilder()
            ->select('x')
            ->from($classe, 'x')
            ->andWhere('x.pasta = :pasta')
            ->andWhere('x.tenant = :tenant')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->orderBy('x.' . $ordem, $sentido)
            ->addOrderBy('x.id', $sentido)
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }
}
