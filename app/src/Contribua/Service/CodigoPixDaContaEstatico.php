<?php

declare(strict_types=1);

namespace App\Contribua\Service;

use App\Contribua\DTO\Solicitante;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** O BR Code estático do desenho 08 (chave CNPJ da BlueJus, txid `BJ<conta>`): funciona sem banco nenhum. */
#[AsAlias(id: CodigoPixDaConta::class)]
final class CodigoPixDaContaEstatico implements CodigoPixDaConta
{
    public function para(Solicitante $quem, int $valorCentavos): array
    {
        $codigo = CodigoPixEstatico::gerar(CodigoPixEstatico::txidDaConta($quem->numeroDaConta()), max(0, $valorCentavos));

        return ['codigo' => $codigo, 'valido' => CodigoPixEstatico::valido($codigo)];
    }
}
