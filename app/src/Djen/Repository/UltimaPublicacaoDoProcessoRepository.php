<?php

declare(strict_types=1);

namespace App\Djen\Repository;

use App\Djen\DTO\UltimaPublicacaoDoProcesso;
use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;

final class UltimaPublicacaoDoProcessoRepository
{
    private const LOTE = 500;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Publicação mais recente (não excluída) de cada número, do escritório.
     *
     * @param list<string> $numeros números só com dígitos
     *
     * @return array<string, UltimaPublicacaoDoProcesso> indexado pelo número
     */
    public function dosNumeros(Tenant $tenant, array $numeros): array
    {
        $porNumero = [];
        foreach (array_chunk($numeros, self::LOTE) as $lote) {
            $linhas = $this->em->createQueryBuilder()
                ->select('p.id', 'p.numeroProcesso AS numero', 'p.dataDisponibilizacao AS data', 'p.nomeOrgao', 'p.lida', 'p.prazoDias', 'p.prazoCumpridoEm AS cumpridoEm')
                ->from(PublicacaoDjen::class, 'p')
                ->andWhere('p.tenant = :tenant')
                ->andWhere('p.excluidaEm IS NULL')
                ->andWhere('p.numeroProcesso IN (:numeros)')
                ->orderBy('p.dataDisponibilizacao', 'DESC')
                ->addOrderBy('p.id', 'DESC')
                ->setParameter('tenant', $tenant)
                ->setParameter('numeros', $lote)
                ->getQuery()
                ->getArrayResult();

            foreach ($linhas as $linha) {
                $numero = (string) $linha['numero'];
                $porNumero[$numero] ??= new UltimaPublicacaoDoProcesso(
                    (int) $linha['id'],
                    $linha['data'],
                    $linha['nomeOrgao'],
                    (bool) $linha['lida'],
                    $linha['prazoDias'],
                    $linha['cumpridoEm'],
                );
            }
        }

        return $porNumero;
    }
}
