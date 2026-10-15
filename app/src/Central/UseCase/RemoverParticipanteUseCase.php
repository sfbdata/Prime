<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\Exception\ConversaNaoEncontradaException;
use App\Central\Exception\PedidoInvalidoNaCentralException;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Exception\SemPermissaoNoGrupoException;
use App\Central\Repository\ParticipanteRepository;
use App\Central\Service\AcessoACentral;
use App\Central\Service\MensagensDeSistemaDoGrupo;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Remover alguém do grupo — só o administrador, e nunca a si mesmo (para isso há "Sair"). Quem é removido perde o
 * acesso ao grupo inteiro, inclusive ao histórico (`docs/specs/central-comunicacao-b.md` §2); a participação não é
 * apagada — se for readmitido, volta a ver tudo.
 */
final class RemoverParticipanteUseCase
{
    public function __construct(
        private readonly AcessoACentral $acesso,
        private readonly ParticipanteRepository $participantes,
        private readonly MensagensDeSistemaDoGrupo $sistema,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws SemAcessoACentralException
     * @throws ConversaNaoEncontradaException
     * @throws SemPermissaoNoGrupoException
     * @throws PedidoInvalidoNaCentralException
     */
    public function executar(User $eu, ?Tenant $tenant, int $conversaId, int $usuarioId): void
    {
        $tenant = $this->acesso->exigirVinculoAtivo($eu, $tenant);
        $grupo = $this->acesso->participacao($conversaId, $eu, $tenant)->getConversa();
        if (!$grupo->isGrupo()) {
            throw new PedidoInvalidoNaCentralException('Só se remove participante de um grupo.');
        }
        if (!$grupo->isAdministradaPor($eu)) {
            throw new SemPermissaoNoGrupoException();
        }
        if ($usuarioId === $eu->getId()) {
            throw new PedidoInvalidoNaCentralException('Para deixar o grupo, use "Sair do grupo".');
        }

        $alvo = null;
        foreach ($this->participantes->daConversaInteira($grupo, $tenant) as $participante) {
            if ($participante->getUsuario()->getId() === $usuarioId) {
                $alvo = $participante;
            }
        }
        if ($alvo === null) {
            throw new PedidoInvalidoNaCentralException('Esta pessoa não participa do grupo.');
        }

        $agora = $this->clock->now();
        $alvo->sair($agora);
        $this->sistema->removeu($grupo, $eu, $alvo->getUsuario(), $tenant, $agora);
        $this->entityManager->flush();
    }
}
