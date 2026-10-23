<?php

declare(strict_types=1);

namespace App\Profile\Preferencia;

/**
 * A lista FECHADA das preferências do card "Preferências" do Meu Perfil (desenho "07 - Meu Perfil",
 * VS 18.10.26, `prefs`): chaves liga/desliga gravadas em `preferencia_usuario` (mecanismo do
 * Dashboard, uma linha por usuário, escritório e chave). Padrão do desenho: todas ligadas.
 * Nada fora daqui chega ao banco.
 */
final class CatalogoDePreferenciasDoPerfil
{
    public const NOTIFICACOES = 'perfil.notificacoes';
    public const SONS         = 'perfil.sons';
    public const RESUMO_EMAIL = 'perfil.resumo_email';
    /** D4 de Minhas Metas (spec minhas-metas-esteira-2210, Fase 4): desligada, nada é sugerido nem gravado. */
    public const SUGESTOES_DESPACHOS = 'perfil.sugestoes_despachos';

    /** @return list<string> */
    public static function chaves(): array
    {
        return [self::NOTIFICACOES, self::SONS, self::RESUMO_EMAIL, self::SUGESTOES_DESPACHOS];
    }

    public static function existe(string $chave): bool
    {
        return in_array($chave, self::chaves(), true);
    }

    /**
     * Valores efetivos a partir do que está gravado: o que faltar ou não for booleano vale o
     * padrão (ligado), para uma linha antiga nunca desligar algo sem a pessoa pedir.
     *
     * @param array<string, mixed> $gravados
     *
     * @return array<string, bool>
     */
    public static function efetivas(array $gravados): array
    {
        $efetivas = [];
        foreach (self::chaves() as $chave) {
            $efetivas[$chave] = !array_key_exists($chave, $gravados) || !is_bool($gravados[$chave]) || $gravados[$chave];
        }

        return $efetivas;
    }
}
