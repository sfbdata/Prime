<?php

namespace App\Entity\Auth;

use App\Profile\Repository\UserProfileRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserProfileRepository::class)]
#[ORM\Table(name: 'user_profiles')]
class UserProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'profile')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fotoUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $status = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nomeCompleto = null;

    #[ORM\Column(length: 14, nullable: true)]
    private ?string $cpf = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $dataNascimento = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $ctps = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $serie = null;

    #[ORM\Column]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $atualizadoEm = null;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->criadoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getFotoUrl(): ?string
    {
        return $this->fotoUrl;
    }

    public function setFotoUrl(?string $fotoUrl): static
    {
        $this->fotoUrl = $fotoUrl;
        $this->tocarAtualizadoEm();
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): static
    {
        $this->status = $status;
        $this->tocarAtualizadoEm();
        return $this;
    }

    public function getNomeCompleto(): ?string
    {
        return $this->nomeCompleto;
    }

    public function setNomeCompleto(?string $nomeCompleto): static
    {
        $this->nomeCompleto = $nomeCompleto;
        $this->tocarAtualizadoEm();
        return $this;
    }

    public function getCpf(): ?string
    {
        return $this->cpf;
    }

    public function setCpf(?string $cpf): static
    {
        $this->cpf = $cpf;
        $this->tocarAtualizadoEm();
        return $this;
    }

    public function getDataNascimento(): ?\DateTimeInterface
    {
        return $this->dataNascimento;
    }

    public function setDataNascimento(?\DateTimeInterface $dataNascimento): static
    {
        $this->dataNascimento = $dataNascimento;
        $this->tocarAtualizadoEm();
        return $this;
    }

    public function getCtps(): ?string
    {
        return $this->ctps;
    }

    public function setCtps(?string $ctps): static
    {
        $this->ctps = $ctps;
        $this->tocarAtualizadoEm();
        return $this;
    }

    public function getSerie(): ?string
    {
        return $this->serie;
    }

    public function setSerie(?string $serie): static
    {
        $this->serie = $serie;
        $this->tocarAtualizadoEm();
        return $this;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getAtualizadoEm(): ?\DateTimeImmutable
    {
        return $this->atualizadoEm;
    }

    private function tocarAtualizadoEm(): void
    {
        $this->atualizadoEm = new \DateTimeImmutable();
    }
}
