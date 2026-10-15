<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\DTO\ParticipanteOutput;
use App\Central\DTO\ParticipantesOutput;
use App\Central\Exception\ConversaNaoEncontradaException;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Repository\ParticipanteRepository;
use App\Central\Service\AcessoACentral;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/** Quem está na conversa agora (só participação ativa), para a tela de informações do grupo — só para participante. */
final class ListarParticipantesUseCase
{
    public function __construct(
        private readonly AcessoACentral $acesso,
        private readonly ParticipanteRepository $participantes,
    ) {
    }

    /**
     * @throws SemAcessoACentralException
     * @throws ConversaNaoEncontradaException
     */
    public function executar(User $eu, ?Tenant $tenant, int $conversaId): ParticipantesOutput
    {
        $tenant = $this->acesso->exigirVinculoAtivo($eu, $tenant);
        $conversa = $this->acesso->participacao($conversaId, $eu, $tenant)->getConversa();
        $ativos = $this->acesso->idsComVinculoAtivo($tenant);

        $lista = [];
        foreach ($this->participantes->daConversaInteira($conversa, $tenant) as $participante) {
            $pessoa = $participante->getUsuario();
            $lista[] = new ParticipanteOutput(
                id: (int) $pessoa->getId(),
                nome: (string) $pessoa->getFullName(),
                administrador: $conversa->isAdministradaPor($pessoa),
                ativoNoEscritorio: isset($ativos[(int) $pessoa->getId()]),
                eu: $pessoa->getId() === $eu->getId(),
            );
        }

        return new ParticipantesOutput($conversaId, $conversa->isGrupo(), $conversa->getNome(), $conversa->isAdministradaPor($eu), $lista);
    }
}
