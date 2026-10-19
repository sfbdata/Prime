<?php

declare(strict_types=1);

namespace App\Contribua\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;

/**
 * Avisa o(s) Master(s) de algo do Contribua (sugestão nova, apoio informado, motivo, pedido de espaço)
 * pelo sino e pela Central, como o `avisaMaster` do desenho 08.
 *
 * A notificação é de um escritório (o sino isola por escritório). Vai no escritório de origem quando o
 * Master tem vínculo ativo com ele; senão no primeiro escritório do Master. Master sem escritório
 * nenhum não tem sino para receber, e fica sem o aviso.
 */
final class AvisoAoMaster
{
    public function __construct(
        private readonly UserRepository $usuarios,
        private readonly UserTenantRepository $vinculos,
        private readonly NotificacaoService $notificacoes,
    ) {
    }

    public function avisar(Tenant $origem, string $tipo, string $mensagem, string $url): void
    {
        foreach ($this->usuarios->findComPapelGlobal('ROLE_SUPER_ADMIN') as $master) {
            $escritorio = $this->escritorioDoAviso($master, $origem);
            if ($escritorio !== null) {
                $this->notificacoes->criarNotificacao($master, $escritorio, $tipo, $mensagem, $url);
            }
        }
    }

    private function escritorioDoAviso(User $master, Tenant $origem): ?Tenant
    {
        $escritorios = array_map(static fn ($vinculo): Tenant => $vinculo->getTenant(), $this->vinculos->findActiveByUser($master));
        foreach ($escritorios as $escritorio) {
            if ($escritorio->getId() === $origem->getId()) {
                return $escritorio;
            }
        }

        return $escritorios[0] ?? null;
    }
}
