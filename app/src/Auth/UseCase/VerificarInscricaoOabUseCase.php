<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\ResultadoVerificacaoOab;
use App\Auth\Repository\InscricaoOabRepository;
use App\Auth\Service\ValidadorOab;

/**
 * Verificar UMA inscrição no CNA. Consulta sem resposta (CNA dormente ou fora do ar) nunca sobrescreve um resultado
 * registrado: Confirmada ou Divergente (com a data) continua como estava, e a que ainda não tinha resultado continua
 * Não verificada. Devolve o resultado da consulta: a tela diz se houve resposta ({@see ResultadoVerificacaoOab::houveResposta()}).
 * Para a fila do Master por inscrição (frente b5; spec docs/specs/meu-perfil-oab-inscricoes.md §3): recebe o id da
 * inscrição (principal ou suplementar), é global e só pode ser chamado por quem é Master. Quando a inscrição é a
 * principal, o espelho User.oab* acompanha. Auditado pelo AuditLogSubscriber (InscricaoOab e User são Auditavel).
 *
 * @throws \DomainException inscrição inexistente
 */
final class VerificarInscricaoOabUseCase
{
    public function __construct(
        private readonly InscricaoOabRepository $inscricoes,
        private readonly ValidadorOab $validador,
    ) {
    }

    public function executar(int $inscricaoId): ResultadoVerificacaoOab
    {
        $inscricao = $this->inscricoes->find($inscricaoId) ?? throw new \DomainException('Inscrição não encontrada.');
        $resultado = $this->validador->verificar($inscricao->getNumero(), $inscricao->getUf(), (string) $inscricao->getUsuario()->getFullName());
        if (!$resultado->houveResposta()) {
            return $resultado;
        }
        $inscricao->registrarVerificacao($resultado->status, $resultado->nomeOficial);
        if ($inscricao->ehPrincipal()) {
            $inscricao->espelharNoUsuario();
        }
        $this->inscricoes->salvar($inscricao, flush: true);

        return $resultado;
    }
}
