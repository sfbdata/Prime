<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\DTO\GrupoOutput;
use App\Central\Exception\ConversaNaoEncontradaException;
use App\Central\Exception\PedidoInvalidoNaCentralException;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Service\AcessoACentral;
use App\Central\Service\MensagensDeSistemaDoGrupo;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Renomear o grupo — qualquer participante ativo, como no desenho (L6191, L5768; decisão aberta ao dono em
 * `central-comunicacao-b.md` §8). Nome 1–60; o mesmo nome não grava nem anuncia nada.
 */
final class RenomearGrupoUseCase
{
    public function __construct(
        private readonly AcessoACentral $acesso,
        private readonly MensagensDeSistemaDoGrupo $sistema,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws SemAcessoACentralException
     * @throws ConversaNaoEncontradaException
     * @throws PedidoInvalidoNaCentralException
     */
    public function executar(User $eu, ?Tenant $tenant, int $conversaId, string $nome): GrupoOutput
    {
        $tenant = $this->acesso->exigirVinculoAtivo($eu, $tenant);
        $grupo = $this->acesso->participacao($conversaId, $eu, $tenant)->getConversa();
        if (!$grupo->isGrupo()) {
            throw new PedidoInvalidoNaCentralException('Só grupo tem nome.');
        }

        $antes = $grupo->getNome();
        try {
            $grupo->renomear($nome);
        } catch (\DomainException $e) {
            throw new PedidoInvalidoNaCentralException($e->getMessage());
        }

        if ($grupo->getNome() !== $antes) {
            $this->sistema->renomeou($grupo, $eu, $tenant, $this->clock->now());
            $this->entityManager->flush();
        }

        return new GrupoOutput((int) $grupo->getId(), (string) $grupo->getNome());
    }
}
