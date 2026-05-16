<?php
declare(strict_types=1);
namespace App\Profile\Entity;

use App\Entity\Auth\User;
use App\Profile\Repository\UserProfileRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserProfileRepository::class)]
#[ORM\Table(name: 'user_profiles')]
#[ORM\HasLifecycleCallbacks]
class UserProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

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

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $atualizadoEm = null;

    public function __construct(
        #[ORM\OneToOne(inversedBy: 'profile')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
    ) {
        $this->criadoEm = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function aoAtualizar(): void
    {
        $this->atualizadoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
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

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getNomeCompleto(): ?string
    {
        return $this->nomeCompleto;
    }

    public function setNomeCompleto(?string $nomeCompleto): static
    {
        $this->nomeCompleto = $nomeCompleto;

        return $this;
    }

    public function getCpf(): ?string
    {
        return $this->cpf;
    }

    public function setCpf(?string $cpf): static
    {
        $this->cpf = $cpf;

        return $this;
    }

    public function getDataNascimento(): ?\DateTimeInterface
    {
        return $this->dataNascimento;
    }

    public function setDataNascimento(?\DateTimeInterface $dataNascimento): static
    {
        $this->dataNascimento = $dataNascimento;

        return $this;
    }

    public function getCtps(): ?string
    {
        return $this->ctps;
    }

    public function setCtps(?string $ctps): static
    {
        $this->ctps = $ctps;

        return $this;
    }

    public function getSerie(): ?string
    {
        return $this->serie;
    }

    public function setSerie(?string $serie): static
    {
        $this->serie = $serie;

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
}
