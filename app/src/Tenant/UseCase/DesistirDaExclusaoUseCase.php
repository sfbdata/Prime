<?php

declare(strict_types=1);

namespace App\Tenant\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tenant\Exception\PedidoDeExclusaoRecusado;
use App\Tenant\Repository\PedidoDeExclusaoDeContaRepository;
use App\Tenant\Service\GuardaDaExclusaoPeloPrimario;
use Psr\Clock\ClockInterface;

/**
 * "Desistir e reativar" (PR 5; desenho 07 L114, L266): um primário (qualquer um, decisão E1) desiste do
 * pedido pendente e a equipe volta. Conta fora do ar (suspensa pelo Master ou em quarentena) é recusada:
 * aí só o Master decide (E9).
 *
 * @throws PedidoDeExclusaoRecusado
 */
final class DesistirDaExclusaoUseCase
{
    public function __construct(
        private readonly GuardaDaExclusaoPeloPrimario $guarda,
        private readonly PedidoDeExclusaoDeContaRepository $pedidos,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(User $usuario, Tenant $tenant): void
    {
        $this->guarda->exigir($usuario, $tenant);
        $pedido = $this->pedidos->pendenteDoEscritorio($tenant);
        if ($pedido === null) {
            throw new PedidoDeExclusaoRecusado('Não há pedido de exclusão para desistir.', 409);
        }

        $pedido->desistir($usuario, $this->relogio->now());
        $this->pedidos->salvar($pedido, flush: true);
    }
}
