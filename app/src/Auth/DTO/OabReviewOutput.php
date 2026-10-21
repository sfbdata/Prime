<?php

declare(strict_types=1);

namespace App\Auth\DTO;

use App\Auth\Entity\InscricaoOab;
use App\Auth\Enum\StatusOab;
use App\Auth\Enum\TipoInscricaoOab;

/**
 * Linha da tela de revisão de OAB do super-admin: UMA inscrição (principal ou suplementar), com o nome e o
 * e-mail do usuário dono dela. `id` é o id da inscrição (as ações agem por ele). Global, sem tenant.
 */
final class OabReviewOutput
{
    public function __construct(
        public readonly int $id,
        public readonly string $nome,
        public readonly string $email,
        public readonly string $oabNumero,
        public readonly string $oabUf,
        public readonly StatusOab $oabStatus,
        public readonly ?string $oabNomeOficial,
        public readonly ?\DateTimeImmutable $oabVerificadaEm,
        public readonly TipoInscricaoOab $tipo,
    ) {
    }

    public static function fromInscricao(InscricaoOab $inscricao): self
    {
        $usuario = $inscricao->getUsuario();

        return new self(
            id: (int) $inscricao->getId(),
            nome: (string) $usuario->getFullName(),
            email: (string) $usuario->getEmail(),
            oabNumero: $inscricao->getNumero(),
            oabUf: $inscricao->getUf(),
            oabStatus: $inscricao->getStatus(),
            oabNomeOficial: $inscricao->getNomeOficial(),
            oabVerificadaEm: $inscricao->getVerificadaEm(),
            tipo: $inscricao->getTipo(),
        );
    }
}
