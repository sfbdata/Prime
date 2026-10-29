<?php

declare(strict_types=1);

namespace App\ChatIa\Service;

use App\ChatIa\Entity\ConversaDeChat;
use App\ChatIa\Exception\ConversaDeChatNaoEncontradaException;
use App\ChatIa\Exception\SemAcessoAoChatException;
use App\ChatIa\Repository\ConversaDeChatRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Service\DisponibilidadeDeInteligencia;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;

/**
 * As guardas do Chat I.A, num lugar só: vínculo ATIVO com o escritório da sessão e a permissão do módulo da I.A
 * (`modules.inteligencia.view`); e a POSSE da conversa (do dono, no escritório; qualquer outra é 404, igual para a
 * que não existe).
 */
final class AcessoAoChat
{
    public function __construct(
        private readonly UserTenantRepository $vinculos,
        private readonly PermissionChecker $permissoes,
        private readonly ConversaDeChatRepository $conversas,
    ) {
    }

    /** @throws SemAcessoAoChatException */
    public function exigir(User $usuario, ?Tenant $tenant): Tenant
    {
        if ($tenant === null || !$this->vinculos->existeVinculoAtivo($usuario, $tenant)) {
            throw new SemAcessoAoChatException();
        }
        if (!$this->permissoes->canAccessModule($usuario, $tenant, DisponibilidadeDeInteligencia::MODULO)) {
            throw new SemAcessoAoChatException('Você não tem permissão para usar a I.A.');
        }

        return $tenant;
    }

    /** @throws ConversaDeChatNaoEncontradaException */
    public function conversaDoDono(int $id, User $usuario, Tenant $tenant): ConversaDeChat
    {
        return ($id > 0 ? $this->conversas->doDono($id, $tenant, $usuario) : null)
            ?? throw new ConversaDeChatNaoEncontradaException();
    }
}
