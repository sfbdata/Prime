<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\Exception\ConversaNaoEncontradaException;
use App\Central\Exception\PedidoInvalidoNaCentralException;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Repository\ParticipanteRepository;
use App\Central\Service\AcessoACentral;
use App\Central\Service\MensagensDeSistemaDoGrupo;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Sair do grupo (qualquer participante ativo). Quem sai perde o acesso ao grupo. Se quem sai é o administrador, a
 * administração passa ao participante ativo mais antigo; sem ninguém, o grupo fica sem administrador
 * (`docs/specs/central-comunicacao-b.md` §2 — o desenho, L6200, passa ao primeiro membro).
 */
final class SairDoGrupoUseCase
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
     * @throws PedidoInvalidoNaCentralException
     */
    public function executar(User $eu, ?Tenant $tenant, int $conversaId): void
    {
        $tenant = $this->acesso->exigirVinculoAtivo($eu, $tenant);
        $participacao = $this->acesso->participacao($conversaId, $eu, $tenant);
        $grupo = $participacao->getConversa();
        if (!$grupo->isGrupo()) {
            throw new PedidoInvalidoNaCentralException('Só se sai de um grupo.');
        }

        $agora = $this->clock->now();
        if ($grupo->isAdministradaPor($eu)) {
            $sucessor = null;
            foreach ($this->participantes->daConversaInteira($grupo, $tenant) as $outro) {
                if ($outro->getUsuario()->getId() !== $eu->getId()) {
                    $sucessor = $outro->getUsuario();
                    break;
                }
            }
            $grupo->passarAdministracaoPara($sucessor);
        }

        $participacao->sair($agora);
        $this->sistema->saiu($grupo, $eu, $tenant, $agora);
        $this->entityManager->flush();
    }
}
