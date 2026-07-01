<?php

declare(strict_types=1);

namespace App\Auth\Entity;

use App\Auth\Repository\CadastroPendenteRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Cadastro público iniciado e ainda não confirmado por e-mail. Guarda os dados do
 * auto-cadastro (conta + primeiro escritório) até o clique no link de confirmação,
 * quando viram User + Tenant + vínculo dono.
 *
 * NÃO é multi-tenant (é pré-conta/pré-escritório — o tenant só nasce na confirmação),
 * por isso não tem campo `tenant` nem é TenantAware. A chave externa é o `token`, nunca o id.
 */
#[ORM\Entity(repositoryClass: CadastroPendenteRepository::class)]
#[ORM\Table(name: 'cadastro_pendente')]
#[ORM\UniqueConstraint(name: 'uq_cadastro_pendente_token', columns: ['token'])]
#[ORM\Index(name: 'idx_cadastro_pendente_email', columns: ['email'])]
class CadastroPendente
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $status = 'pending';

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    public function __construct(
        #[ORM\Column(length: 180)]
        private string $email,

        #[ORM\Column(length: 64)]
        private string $token,

        #[ORM\Column(length: 255)]
        private string $nomeCompleto,

        #[ORM\Column(length: 255)]
        private string $nomeEscritorio,

        #[ORM\Column(length: 20)]
        private string $oabNumero,

        #[ORM\Column(length: 2)]
        private string $oabUf,

        #[ORM\Column(length: 255)]
        private string $senhaHash,

        #[ORM\Column(length: 45)]
        private string $ip,

        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $expiresAt,

        #[ORM\Column(length: 255, nullable: true)]
        private ?string $userAgent = null,

        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }

    public function getId(): ?int { return $this->id; }

    public function getEmail(): string { return $this->email; }

    public function getToken(): string { return $this->token; }

    public function getNomeCompleto(): string { return $this->nomeCompleto; }

    public function getNomeEscritorio(): string { return $this->nomeEscritorio; }

    public function getOabNumero(): string { return $this->oabNumero; }

    public function getOabUf(): string { return $this->oabUf; }

    public function getSenhaHash(): string { return $this->senhaHash; }

    public function getIp(): string { return $this->ip; }

    public function getUserAgent(): ?string { return $this->userAgent; }

    public function getStatus(): string { return $this->status; }

    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getConfirmedAt(): ?\DateTimeImmutable { return $this->confirmedAt; }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }

    public function confirmar(): void
    {
        $this->status      = 'confirmado';
        $this->confirmedAt = new \DateTimeImmutable();
    }
}
