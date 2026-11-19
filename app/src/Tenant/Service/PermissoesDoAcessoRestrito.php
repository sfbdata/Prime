<?php

declare(strict_types=1);

namespace App\Tenant\Service;

use App\Entity\Tenant\TenantRole;

/**
 * As permissões que um perfil com "ACESSO RESTRITO ÀS PASTAS SOB RESPONSABILIDADE" pode usar (spec
 * `acesso-restrito-e-compartilhar.md`, §5.1 item 1). É LISTA DE LIBERAÇÃO: só a Lista de pastas, a pasta e o que vive
 * dentro dela (processo, cliente). Tudo o mais fica fechado, inclusive código que entrar no catálogo depois:
 * administração inteira, BlueJus IA e Chat I.A (Q2), Agenda e "Ver prazos na agenda", Cobrança e "Outros recursos",
 * Voto, Assinatura e os demais módulos (D4).
 *
 * Teto, nunca concessão: a permissão liberada aqui ainda precisa estar marcada no perfil.
 */
final class PermissoesDoAcessoRestrito
{
    public const LIBERADAS = [
        'modules.expediente.view',
        'modules.pastas.view',
        'modules.processos.view',
        'modules.clientes.view',
        'resources.pasta.view',
        'resources.pasta.edit',
        'resources.pasta.delete',
        'resources.cliente.view',
        'resources.cliente.edit',
        'resources.cliente.delete',
        'resources.processo.view',
        'resources.processo.edit',
        'resources.processo.delete',
    ];

    /** `true` quando o perfil é restrito e a permissão está fora da lista de liberação. */
    public static function bloqueia(?TenantRole $perfil, string $permissao): bool
    {
        return $perfil !== null && $perfil->temAcessoRestrito() && !in_array($permissao, self::LIBERADAS, true);
    }
}
