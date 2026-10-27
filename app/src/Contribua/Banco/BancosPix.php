<?php

declare(strict_types=1);

namespace App\Contribua\Banco;

use App\Contribua\Banco\Cora\BancoCora;

/** Os bancos PIX disponíveis, pelo código. Só a Cora agora; Inter, BB e "Outro" entram aqui (C4b, C4c). */
final class BancosPix
{
    /** @var array<string, BancoPix> */
    private array $porCodigo;

    public function __construct(BancoCora $cora)
    {
        $this->porCodigo = [$cora->codigo() => $cora];
    }

    public function doCodigo(string $codigo): ?BancoPix
    {
        return $this->porCodigo[$codigo] ?? null;
    }
}
