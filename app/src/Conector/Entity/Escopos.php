<?php

declare(strict_types=1);

namespace App\Conector\Entity;

/**
 * Lista de escopos OAuth gravada em JSON no consentimento, no código e no token. Aqui só a forma (lista de textos sem
 * espaço, sem repetição); quais escopos existem (`mcp:leitura`, `mcp:escrita`, `offline_access`, seção 5.1) é regra do
 * servidor de autorização (PR 3).
 */
final class Escopos
{
    /**
     * @param array<mixed> $escopos
     *
     * @return list<string>
     */
    public static function exigir(array $escopos): array
    {
        if ($escopos === [] || !array_is_list($escopos)) {
            throw new \InvalidArgumentException('A autorização precisa de pelo menos um escopo.');
        }

        foreach ($escopos as $escopo) {
            if (!\is_string($escopo) || preg_match('/^[\x21\x23-\x5B\x5D-\x7E]+$/', $escopo) !== 1) {
                throw new \InvalidArgumentException('Escopo inválido.');
            }
        }

        if (\count(array_unique($escopos)) !== \count($escopos)) {
            throw new \InvalidArgumentException('Escopo repetido.');
        }

        return $escopos;
    }
}
