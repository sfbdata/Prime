<?php

declare(strict_types=1);

namespace App\Auth\Entity;

use App\Auth\Repository\ConfirmacaoEmailRepository;
use App\Entity\Auth\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * Link de confirmação do e-mail de uma conta que já existe (cadastro do desenho 10). Como a RedefinicaoSenha, o
 * banco guarda só o SHA-256 do token. `lembrete` marca o reenvio automático único do Comercial (5d): é por ele que
 * a consulta do lembrete sabe que a conta já recebeu o seu.
 *
 * NÃO é multi-tenant: a identidade do User é global.
 */
#[ORM\Entity(repositoryClass: ConfirmacaoEmailRepository::class)]
#[ORM\Table(name: 'confirmacao_email')]
#[ORM\UniqueConstraint(name: 'uq_confirmacao_email_token_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_confirmacao_email_user', columns: ['user_id'])]
class ConfirmacaoEmail
{
    public const VALIDADE = '+7 days';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $usadoEm = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 64)]
        private string $tokenHash,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $expiraEm,
        #[ORM\Column(type: 'boolean')]
        private bool $lembrete = false,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $criadoEm = new \DateTimeImmutable(),
    ) {
    }

    /** Único ponto que decide como o token vira chave de busca. */
    public static function hashDoToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getExpiraEm(): \DateTimeImmutable
    {
        return $this->expiraEm;
    }

    public function isLembrete(): bool
    {
        return $this->lembrete;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getUsadoEm(): ?\DateTimeImmutable
    {
        return $this->usadoEm;
    }

    public function isExpirado(\DateTimeImmutable $agora): bool
    {
        return $this->expiraEm < $agora;
    }

    public function marcarUsado(\DateTimeImmutable $agora): void
    {
        $this->usadoEm ??= $agora;
    }
}
