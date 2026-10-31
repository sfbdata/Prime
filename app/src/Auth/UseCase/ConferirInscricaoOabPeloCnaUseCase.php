<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\Entity\InscricaoOab;
use App\Auth\Enum\StatusOab;
use App\Auth\Repository\InscricaoOabRepository;
use App\Auth\Service\ValidadorOab;

/**
 * Conferência manual de UMA inscrição pelo nome que o Master leu no CNA (desenho 05 - Perfis 1.2 de 31/10, botão
 * "Verificar de novo (nome no CNA)" da Revisão de OAB). Enquanto a consulta automática ao CNA está dormente, é o
 * Master quem consulta o site da OAB e digita o nome que consta lá.
 *
 * Nome em branco: o Master não localizou a inscrição, ela volta a Não verificada. Nome informado: bate com o nome da
 * pessoa pelo mesmo matcher tolerante da consulta automática ({@see ValidadorOab::nomesBatem()}, decisão do dono em
 * docs/specs/validacao-oab.md) e vira Confirmada; não bate, Divergente. O nome lido fica como nome oficial.
 * Quando a inscrição é a principal, o espelho User.oab* acompanha. Auditado pelo AuditLogSubscriber.
 *
 * @throws \DomainException inscrição inexistente
 */
final class ConferirInscricaoOabPeloCnaUseCase
{
    public function __construct(
        private readonly InscricaoOabRepository $inscricoes,
        private readonly ValidadorOab $validador,
    ) {
    }

    public function executar(int $inscricaoId, string $nomeNoCna): InscricaoOab
    {
        $inscricao = $this->inscricoes->find($inscricaoId) ?? throw new \DomainException('Inscrição não encontrada.');
        $nomeNoCna = trim((string) preg_replace('/\s+/u', ' ', $nomeNoCna));

        if ($nomeNoCna === '') {
            $inscricao->registrarVerificacao(StatusOab::NaoVerificada, null);
        } else {
            $bate = $this->validador->nomesBatem((string) $inscricao->getUsuario()->getFullName(), $nomeNoCna);
            $inscricao->registrarVerificacao($bate ? StatusOab::Confirmada : StatusOab::Divergente, mb_substr($nomeNoCna, 0, 255));
        }

        if ($inscricao->ehPrincipal()) {
            $inscricao->espelharNoUsuario();
        }
        $this->inscricoes->salvar($inscricao, flush: true);

        return $inscricao;
    }
}
