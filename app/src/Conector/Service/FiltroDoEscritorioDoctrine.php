<?php

declare(strict_types=1);

namespace App\Conector\Service;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * O {@see FiltroDoEscritorio} de verdade, no EntityManager da aplicação. Liga como o TenantFilterListener do navegador
 * (mesmo nome, mesmo parâmetro, mesmo tipo) e confere lendo o estado do próprio Doctrine: `isEnabled('tenant')` e o
 * parâmetro igual ao escritório (o TenantFilter fica inerte sem parâmetro, e é isso que a conferência impede).
 */
final class FiltroDoEscritorioDoctrine implements FiltroDoEscritorio
{
    private const FILTRO = 'tenant';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function ligar(int $tenantId): void
    {
        $this->em->getFilters()
            ->enable(self::FILTRO)
            ->setParameter(self::FILTRO, $tenantId, Types::INTEGER);
    }

    public function estaLigadoEm(int $tenantId): bool
    {
        $filtros = $this->em->getFilters();
        if (!$filtros->isEnabled(self::FILTRO)) {
            return false;
        }
        $filtro = $filtros->getFilter(self::FILTRO);
        if (!$filtro->hasParameter(self::FILTRO)) {
            return false;
        }

        // getParameter devolve o valor já citado pela conexão: compara com o escritório citado do mesmo jeito.
        return $filtro->getParameter(self::FILTRO) === $this->em->getConnection()->quote((string) $tenantId);
    }
}
