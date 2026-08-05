<?php

declare(strict_types=1);

namespace App\Auth\Entity;

use App\Auth\Repository\RedefinicaoSenhaRepository;
use App\Entity\Auth\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * Pedido de redefinição de senha ainda não consumido. A chave externa é o token,
 * enviado por e-mail e nunca persistido em claro: o banco guarda só o SHA-256 dele.
 *
 * Diferente do CadastroPendente (que guarda o token em claro por ser pré-conta), este
 * token destrava uma conta que já existe — vazamento do banco não pode virar tomada de
 * conta. Uso único (`usadoEm`) e validade curta (1h) limitam a janela.
 *
 * NÃO é multi-tenant: a identidade do User é global, anterior a qualquer escritório.
 */
#[ORM\Entity(repositoryClass: RedefinicaoSenhaRepository::class)]
#[ORM\Table(name: 'redefinicao_senha')]
#[ORM\UniqueConstraint(name: 'uq_redefinicao_senha_token_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_redefinicao_senha_user', columns: ['user_id'])]
class RedefinicaoSenha
{
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

        #[ORM\Column(length: 45)]
        private string $ip,

        #[ORM\Column(length: 255, nullable: true)]
        private ?string $userAgent = null,

        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $criadoEm = new \DateTimeImmutable(),
    ) {
    }

    /**
     * Hash de conferência do token. Único ponto que decide como o token vira chave de
     * busca — usar sempre este método, nunca hash('sha256', ...) espalhado pelo código.
     */
    public static function hashDoToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): User { return $this->user; }

    public function getTokenHash(): string { return $this->tokenHash; }

    public function getExpiraEm(): \DateTimeImmutable { return $this->expiraEm; }

    public function getIp(): string { return $this->ip; }

    public function getUserAgent(): ?string { return $this->userAgent; }

    public function getCriadoEm(): \DateTimeImmutable { return $this->criadoEm; }

    public function getUsadoEm(): ?\DateTimeImmutable { return $this->usadoEm; }

    public function isUsado(): bool
    {
        return $this->usadoEm !== null;
    }

    public function isExpirado(?\DateTimeImmutable $agora = null): bool
    {
        return $this->expiraEm < ($agora ?? new \DateTimeImmutable());
    }

    public function estaValido(?\DateTimeImmutable $agora = null): bool
    {
        return !$this->isUsado() && !$this->isExpirado($agora);
    }

    public function marcarUsado(?\DateTimeImmutable $agora = null): void
    {
        $this->usadoEm = $agora ?? new \DateTimeImmutable();
    }
}
