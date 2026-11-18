<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Voto\Entity\UnidadeDaAssembleia;

/**
 * Como a unidade aparece escrita nas telas (a ata e o painel de unidades): a regra mora aqui, num lugar só.
 *
 * A identificação como o desenho (`masc`): o CPF de 11 dígitos sai mascarado ("***.456.789-**"). A identificação é
 * única só dentro do bloco (UNIQUE assembleia, bloco, identificação): o bloco vem na frente só quando ela se repete.
 */
final class IdentificacaoDaUnidade
{
    private function __construct()
    {
    }

    /**
     * As identificações que se repetem em mais de um bloco, por identificação (use com `isset`).
     *
     * @param list<UnidadeDaAssembleia> $unidades todas as unidades da assembleia
     *
     * @return array<string, int>
     */
    public static function repetidas(array $unidades): array
    {
        return array_filter(
            array_count_values(array_map(static fn (UnidadeDaAssembleia $u): string => $u->getIdentificacao(), $unidades)),
            static fn (int $n): bool => $n > 1,
        );
    }

    public static function exibir(UnidadeDaAssembleia $unidade, bool $repetida): string
    {
        $id = $unidade->getIdentificacao();
        if (1 === preg_match('/^\d{11}$/', $id)) {
            $id = '***.' . substr($id, 3, 3) . '.' . substr($id, 6, 3) . '-**';
        }

        return !$repetida || '' === $unidade->getBloco() ? $id : $unidade->getBloco() . ' · ' . $id;
    }
}
