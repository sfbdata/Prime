<?php

declare(strict_types=1);

namespace App\Tenant\UseCase;

use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;

final class GerarCodigoFuncionario
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Retorna o próximo código sequencial disponível para o tenant.
     * Considera apenas códigos puramente numéricos para calcular o próximo número.
     * Códigos alfanuméricos customizados são ignorados no cálculo.
     */
    public function executar(Tenant $tenant): string
    {
        $rows = $this->em->createQuery(
            'SELECT ut.codigoFuncionario
             FROM App\Entity\Auth\UserTenant ut
             WHERE ut.tenant = :tenant
               AND ut.codigoFuncionario IS NOT NULL'
        )
        ->setParameter('tenant', $tenant)
        ->getArrayResult();

        $maxNum = 0;
        foreach ($rows as $row) {
            $val = $row['codigoFuncionario'];
            if (ctype_digit($val) && (int) $val > $maxNum) {
                $maxNum = (int) $val;
            }
        }

        return str_pad((string) ($maxNum + 1), 4, '0', STR_PAD_LEFT);
    }
}
