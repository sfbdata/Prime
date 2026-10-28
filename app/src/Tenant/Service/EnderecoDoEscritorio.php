<?php

declare(strict_types=1);

namespace App\Tenant\Service;

use App\Entity\Tenant\Tenant;

/** A linha única de endereço da tela Meu Escritório: logradouro, número, complemento e bairro, sem partes vazias. */
final class EnderecoDoEscritorio
{
    public static function linha(Tenant $tenant): ?string
    {
        $partes = array_filter(
            [$tenant->getLogradouro(), $tenant->getNumero(), $tenant->getComplemento(), $tenant->getBairro()],
            static fn (?string $p): bool => $p !== null && trim($p) !== '',
        );

        return $partes === [] ? null : implode(', ', array_map('trim', $partes));
    }

    public static function cidadeUf(Tenant $tenant): ?string
    {
        $cidade = trim((string) $tenant->getCidade());
        $uf = trim((string) $tenant->getEstado());
        if ($cidade === '' && $uf === '') {
            return null;
        }

        return $uf === '' ? $cidade : ($cidade === '' ? $uf : $cidade . '/' . $uf);
    }
}
