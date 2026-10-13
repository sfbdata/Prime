<?php

declare(strict_types=1);

namespace App\Inteligencia\Preferencia;

/**
 * A lista FECHADA do que a BlueJus IA grava em `preferencia_usuario` (mecanismo do Dashboard:
 * uma linha por usuário, escritório e chave; ver {@see \App\Dashboard\Entity\PreferenciaDoUsuario}).
 * Nada fora daqui chega ao banco, e o valor gravado é sempre o normalizado por este catálogo.
 *
 * `push.ia.recolhidas` (IA-010): as análises do Push que o usuário RECOLHEU (desenho 02 -
 * EXPEDIENTES 1.2.3, dc L5137-5139, `pushIAFech`; README "recolher/expandir (preferência
 * salva)"). O protótipo guardava no navegador; aqui fica no servidor, por usuário. Valor: lista
 * de ids de análise, inteiros positivos, sem repetição, na ordem em que foram recolhidas, com
 * no máximo {@see self::MAXIMO_RECOLHIDAS} (as mais antigas saem primeiro). Padrão: lista vazia
 * (toda análise nasce aberta, como no desenho).
 *
 * A posse do id (análise do escritório e da pasta) é conferida pelo UseCase ANTES de gravar; o
 * catálogo só garante a forma.
 */
final class CatalogoDePreferenciasDaInteligencia
{
    public const PUSH_RECOLHIDAS = 'push.ia.recolhidas';

    public const MAXIMO_RECOLHIDAS = 200;

    /** @return list<string> */
    public static function chaves(): array
    {
        return [self::PUSH_RECOLHIDAS];
    }

    /**
     * Lê o valor gravado de forma TOLERANTE (o que não for id válido é ignorado): uma linha
     * antiga ou corrompida nunca derruba a tela, só deixa de recolher.
     *
     * @return list<int>
     */
    public static function recolhidasDe(mixed $valor): array
    {
        if (!is_array($valor)) {
            return [];
        }

        $ids = [];
        foreach ($valor as $id) {
            if (is_int($id) && $id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return array_slice($ids, -self::MAXIMO_RECOLHIDAS);
    }

    /**
     * A lista nova depois de recolher (acrescenta no fim) ou expandir (retira) uma análise.
     *
     * @param list<int> $atuais
     *
     * @return list<int>
     */
    public static function alternar(array $atuais, int $analiseId, bool $recolhida): array
    {
        $sem = array_values(array_filter($atuais, static fn (int $id): bool => $id !== $analiseId));
        if (!$recolhida) {
            return $sem;
        }

        $sem[] = $analiseId;

        return array_slice($sem, -self::MAXIMO_RECOLHIDAS);
    }
}
