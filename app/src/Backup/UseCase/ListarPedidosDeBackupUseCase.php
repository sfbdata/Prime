<?php

declare(strict_types=1);

namespace App\Backup\UseCase;

use App\Backup\DTO\PedidoDeBackupLinha;
use App\Backup\Entity\PedidoDeBackup;
use App\Backup\Enum\SituacaoDoBackup;
use App\Backup\Repository\PedidoDeBackupRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Psr\Clock\ClockInterface;

/**
 * Os últimos pedidos de backup DA PESSOA no escritório atual, para a lista do Meu Perfil. O escritório e a pessoa vão
 * escritos na consulta: pedido de colega ou de outro escritório nunca entra.
 */
final class ListarPedidosDeBackupUseCase
{
    public const LIMITE = 5;

    public function __construct(
        private readonly PedidoDeBackupRepository $pedidos,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return list<PedidoDeBackupLinha> */
    public function executar(User $usuario, Tenant $tenant): array
    {
        $agora = \DateTimeImmutable::createFromInterface($this->clock->now());
        $linhas = [];
        foreach ($this->pedidos->ultimosDaPessoa((int) $tenant->getId(), (int) $usuario->getId(), self::LIMITE) as $pedido) {
            $linhas[] = new PedidoDeBackupLinha(
                (int) $pedido->getId(),
                self::situacaoVisivel($pedido, $agora),
                $pedido->getPedidoEm(),
                $pedido->getTamanhoBytes(),
                $pedido->getExpiraEm(),
                $pedido->podeSerBaixadoPor($usuario, $tenant, $agora),
            );
        }

        return $linhas;
    }

    private static function situacaoVisivel(PedidoDeBackup $pedido, \DateTimeImmutable $agora): string
    {
        if ($pedido->venceuEm($agora)) {
            return SituacaoDoBackup::Vencido->value;
        }

        return match ($pedido->getSituacao()) {
            SituacaoDoBackup::Pedido, SituacaoDoBackup::Montando => 'em_preparo',
            default => $pedido->getSituacao()->value,
        };
    }
}
