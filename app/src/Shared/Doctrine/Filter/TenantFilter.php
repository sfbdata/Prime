<?php

declare(strict_types=1);

namespace App\Shared\Doctrine\Filter;

use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Filtro Doctrine que injeta `tenant_id = :tenant` em TODA query de entidade que implementa
 * TenantAware. Habilitado por request (TenantFilterListener) com o tenant atual; em CLI/teste
 * fica desligado por padrão e é habilitado manualmente quando preciso.
 *
 * Sem o parâmetro `tenant` setado, o filtro é inerte (não restringe nada) — evita quebrar
 * contextos sem tenant (super admin, console).
 */
final class TenantFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, $targetTableAlias): string
    {
        $refl = $targetEntity->reflClass;
        if ($refl === null || !$refl->implementsInterface(TenantAware::class)) {
            return '';
        }

        try {
            $tenantId = $this->getParameter('tenant');
        } catch (\InvalidArgumentException) {
            return '';
        }

        if ($tenantId === '' || $tenantId === null) {
            return '';
        }

        $coluna = $targetEntity->getSingleAssociationJoinColumnName('tenant');

        // getParameter já devolve o valor escapado/quotado conforme o tipo informado.
        return sprintf('%s.%s = %s', $targetTableAlias, $coluna, $tenantId);
    }
}
