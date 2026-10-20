<?php

declare(strict_types=1);

namespace App\Tenant\Service;

use App\Entity\Auth\UserTenant;
use App\Tenant\Repository\PedidoDeExclusaoDeContaRepository;

/**
 * "Suspensa a pedido" (PR 5, decisão E3; contrato C13): com pedido de exclusão pendente, o escritório
 * continua ativo, mas só os primários entram (no Meu Perfil, para desistir). A regra mora aqui, num lugar
 * só, e vale na barreira (b) do TenantContextValidatorListener, no TenantContext::setCurrentTenant e em
 * quem escolhe ou lista escritórios (login, seleção, seletor da barra, Minhas contas). Sem isso, a seleção
 * escolheria de novo o escritório ainda ativo e cairia em laço com a barreira.
 */
final class BloqueioPorPedidoDeExclusao
{
    public const MENSAGEM = 'Este escritório está suspenso a pedido do usuário primário e aguarda a decisão do BlueJus.';

    public function __construct(
        private readonly PedidoDeExclusaoDeContaRepository $pedidos,
        private readonly RegraDosPrimarios $regraDosPrimarios,
    ) {
    }

    /** O escritório tem pedido pendente (a conta está "suspensa a pedido"). */
    public function suspensaAPedido(int $tenantId): bool
    {
        return $this->pedidos->existePendente($tenantId);
    }

    /** Este vínculo não entra: pedido pendente e a pessoa não é primária. */
    public function bloqueia(UserTenant $vinculo): bool
    {
        $tenantId = $vinculo->getTenant()->getId();

        return $tenantId !== null
            && $this->suspensaAPedido($tenantId)
            && !$this->regraDosPrimarios->ehPrimario($vinculo);
    }

    /**
     * Os vínculos que podem entrar (para o login, a seleção e o seletor).
     *
     * @param array<UserTenant> $vinculos
     *
     * @return list<UserTenant>
     */
    public function permitidos(array $vinculos): array
    {
        return array_values(array_filter($vinculos, fn (UserTenant $v): bool => !$this->bloqueia($v)));
    }

    /**
     * Os nomes dos escritórios barrados por pedido (a seleção mostra o aviso de cada um).
     *
     * @param array<UserTenant> $vinculos
     *
     * @return list<string>
     */
    public function nomesBloqueados(array $vinculos): array
    {
        $nomes = [];
        foreach ($vinculos as $v) {
            if ($this->bloqueia($v)) {
                $nomes[] = (string) $v->getTenant()->getName();
            }
        }

        return $nomes;
    }
}
