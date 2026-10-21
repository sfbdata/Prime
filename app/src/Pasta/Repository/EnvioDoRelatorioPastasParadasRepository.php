<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\RelatorioPastasParadasOutput;
use App\Pasta\Entity\EnvioDoRelatorioPastasParadas;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EnvioDoRelatorioPastasParadas>
 */
class EnvioDoRelatorioPastasParadasRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EnvioDoRelatorioPastasParadas::class);
    }

    /**
     * Grava o envio do mês, se ainda não houver um, e diz se foi ESTA chamada que gravou.
     *
     * SQL direto com `ON CONFLICT (tenant_id, mes) DO NOTHING RETURNING id` (PostgreSQL), como o
     * `DecisaoSemProcessoDaPastaRepository::gravarDecisao`: dois crons ao mesmo tempo não estouram o
     * UNIQUE no caminho do ORM (que fecharia o EntityManager); o banco decide a corrida e só quem
     * inseriu recebe a linha de volta. Dentro de uma transação, o segundo espera o primeiro terminar.
     */
    public function registrarSeNovo(Tenant $tenant, RelatorioPastasParadasOutput $relatorio, \DateTimeImmutable $em): bool
    {
        $id = $this->getEntityManager()->getConnection()->fetchOne(
            'INSERT INTO relatorio_pastas_paradas_envio (tenant_id, mes, paradas, mais_de_90, sem_contrato, sem_processo, em)
             VALUES (:tenant, :mes, :paradas, :maisDe90, :semContrato, :semProcesso, :em)
             ON CONFLICT (tenant_id, mes) DO NOTHING
             RETURNING id',
            [
                'tenant'      => $tenant->getId(),
                'mes'         => EnvioDoRelatorioPastasParadas::mesDe($em),
                'paradas'     => \count($relatorio->pastas),
                'maisDe90'    => $relatorio->maisDe90(),
                'semContrato' => $relatorio->semContrato(),
                'semProcesso' => $relatorio->semProcesso(),
                'em'          => $em->format('Y-m-d H:i:s'),
            ],
        );

        return $id !== false && $id !== null;
    }

    public function existeNoMes(Tenant $tenant, \DateTimeImmutable $quando): bool
    {
        return $this->count(['tenant' => $tenant, 'mes' => EnvioDoRelatorioPastasParadas::mesDe($quando)]) > 0;
    }
}
