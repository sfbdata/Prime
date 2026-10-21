<?php

declare(strict_types=1);

namespace App\Tenant\Service;

use App\Entity\Tenant\Tenant;

/**
 * Número da conta do escritório: o id com 6 dígitos ("000123"). É o "conta Nº 000123" dos avisos do
 * Contribua e do txid do Pix, e o selo da linha da conta no Painel do Master (spec perfis-1.2-permissoes-e-hierarquia
 * A1 e A2). O id é estável e já é a ordem de abertura (com buracos); a posição na tela mudaria com a purga.
 */
final class NumeroDaConta
{
    private const DIGITOS = 6;

    public static function doId(int $id): string
    {
        return str_pad((string) $id, self::DIGITOS, '0', \STR_PAD_LEFT);
    }

    public static function daConta(Tenant $conta): string
    {
        return self::doId((int) $conta->getId());
    }
}
