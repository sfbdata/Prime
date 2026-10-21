<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\Entity\InscricaoOab;
use App\Auth\Enum\StatusOab;
use App\Auth\Repository\InscricaoOabRepository;

/**
 * Reverter a situação de UMA inscrição: volta a Pendente (NaoVerificada) ou marca Divergente.
 * Para a fila do Master por inscrição (frente b5; spec docs/specs/meu-perfil-oab-inscricoes.md §3): recebe o id da
 * inscrição (principal ou suplementar), é global e só pode ser chamado por quem é Master. Quando a inscrição é a
 * principal, o espelho User.oab* acompanha. Auditado pelo AuditLogSubscriber (InscricaoOab e User são Auditavel).
 *
 * @throws \DomainException inscrição inexistente
 */
final class ReverterInscricaoOabUseCase
{
    public function __construct(
        private readonly InscricaoOabRepository $inscricoes,
    ) {
    }

    public function executar(int $inscricaoId, StatusOab $destino): InscricaoOab
    {
        $inscricao = $this->inscricoes->find($inscricaoId) ?? throw new \DomainException('Inscrição não encontrada.');
        if ($destino === StatusOab::Confirmada) {
            throw new \InvalidArgumentException('Destino inválido para reverter OAB: ' . $destino->value);
        }
        $inscricao->registrarVerificacao($destino, $inscricao->getNomeOficial());
        if ($inscricao->ehPrincipal()) {
            $inscricao->espelharNoUsuario();
        }
        $this->inscricoes->salvar($inscricao, flush: true);

        return $inscricao;
    }
}
