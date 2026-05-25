<?php

namespace App\Entity\Auth;

use App\Entity\Ponto\JornadaColaborador;
use App\Profile\Entity\UserProfile;
use App\Repository\UserRepository;
use App\Shared\Contract\Auditavel;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $password = null;

    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column(length: 255)]
    private ?string $fullName = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $invitationToken = null;

    #[ORM\OneToOne(mappedBy: 'user', cascade: ['persist', 'remove'])]
    private ?JornadaColaborador $jornadaColaborador = null;

    #[ORM\OneToOne(mappedBy: 'user', cascade: ['persist', 'remove'])]
    private ?UserProfile $profile = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $oabNumero = null;

    #[ORM\Column(length: 2, nullable: true)]
    private ?string $oabUf = null;

    // -------------------------
    // Construtor
    // -------------------------
    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // -------------------------
    // Métodos obrigatórios
    // -------------------------

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;
        return $this;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        // garante que todo usuário tenha pelo menos ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    public function setRoles(array $roles): self
    {
        $this->roles = $roles;
        return $this;
    }

    public function eraseCredentials(): void
    {
        // Se você armazenar dados sensíveis temporários, limpe-os aqui
    }

    public function getFullName(): ?string
    {
        return $this->fullName;
    }

    public function setFullName(string $fullName): static
    {
        $this->fullName = $fullName;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getInvitationToken(): ?string
    {
        return $this->invitationToken;
    }

    public function setInvitationToken(?string $token): static
    {
        $this->invitationToken = $token;
        return $this;
    }

    public function getJornadaColaborador(): ?JornadaColaborador
    {
        return $this->jornadaColaborador;
    }

    public function setJornadaColaborador(?JornadaColaborador $jornadaColaborador): static
    {
        if ($jornadaColaborador !== null && $jornadaColaborador->getUser() !== $this) {
            $jornadaColaborador->setUser($this);
        }
        $this->jornadaColaborador = $jornadaColaborador;
        return $this;
    }

    public function getProfile(): ?UserProfile
    {
        return $this->profile;
    }

    public function setProfile(?UserProfile $profile): static
    {
        $this->profile = $profile;
        return $this;
    }

    public function getOabNumero(): ?string { return $this->oabNumero; }

    public function setOabNumero(?string $oabNumero): static
    {
        $this->oabNumero = $oabNumero;

        return $this;
    }

    public function getOabUf(): ?string { return $this->oabUf; }

    public function setOabUf(?string $oabUf): static
    {
        $this->oabUf = $oabUf;

        return $this;
    }
}
