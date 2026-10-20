<?php

declare(strict_types=1);

namespace App\Contribua\Banco;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Os bancos PIX disponíveis, pelo código: Cora, Inter, Banco do Brasil e "Outro" (D1 da C4b). Toda
 * implementação de BancoPix entra aqui sozinha (tag da interface).
 */
final class BancosPix
{
    /** @var array<string, BancoPix> */
    private array $porCodigo = [];

    /** @param iterable<BancoPix> $bancos */
    public function __construct(
        #[AutowireIterator(BancoPix::class)]
        iterable $bancos,
    ) {
        foreach ($bancos as $banco) {
            $this->porCodigo[$banco->codigo()] = $banco;
        }
    }

    public function doCodigo(string $codigo): ?BancoPix
    {
        return $this->porCodigo[$codigo] ?? null;
    }

    /** @return list<string> */
    public function codigos(): array
    {
        return array_keys($this->porCodigo);
    }
}
