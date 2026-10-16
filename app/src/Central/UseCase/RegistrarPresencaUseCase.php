<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\Entity\Presenca;
use App\Central\Exception\PedidoInvalidoNaCentralException;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Repository\PresencaRepository;
use App\Central\Service\AcessoACentral;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Psr\Clock\ClockInterface;

/**
 * O batimento de presença (F2): a tela avisa a cada 20 s que a pessoa está com o BlueJus aberto, e com qual status
 * (o manual, ou "ocupado" com "Não perturbe" ligado). Só quem tem vínculo ativo; grava a linha do vínculo NESTE
 * escritório.
 */
final class RegistrarPresencaUseCase
{
    public function __construct(
        private readonly AcessoACentral $acesso,
        private readonly PresencaRepository $presencas,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws SemAcessoACentralException
     * @throws PedidoInvalidoNaCentralException
     */
    public function executar(User $eu, ?Tenant $tenant, string $status): string
    {
        $tenant = $this->acesso->exigirVinculoAtivo($eu, $tenant);
        if (!in_array($status, Presenca::STATUS, true)) {
            throw new PedidoInvalidoNaCentralException('Status inválido.');
        }

        $this->presencas->registrar((int) $tenant->getId(), (int) $eu->getId(), $status, $this->clock->now());

        return $status;
    }
}
