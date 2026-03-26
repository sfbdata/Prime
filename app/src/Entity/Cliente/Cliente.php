<?php

namespace App\Entity\Cliente;

use App\Entity\Auth\User;
use App\Repository\ClienteRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ClienteRepository::class)]
#[ORM\InheritanceType("JOINED")]
#[ORM\DiscriminatorColumn(name: "tipo", type: "string")]
#[ORM\DiscriminatorMap(["pf" => "ClientePF", "pj" => "ClientePJ"])]
#[ORM\HasLifecycleCallbacks]
abstract class Cliente
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $email;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telefoneCelular = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telefoneFixo = null;

    #[ORM\Column(length: 10)]
    private string $cep;

    #[ORM\Column(length: 255)]
    private string $endereco;

    #[ORM\Column(length: 100)]
    private string $cidade;

    #[ORM\Column(length: 2)]
    private string $estado;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $complemento = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $criadoAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $modificadoEm = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $criadoPor = null;

    public function __construct()
    {
        $this->criadoAt = new \DateTimeImmutable();
        $this->modificadoEm = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function setModificadoEmValue(): void
    {
        $this->modificadoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;
        return $this;
    }

    public function getTelefoneCelular(): ?string
    {
        return $this->telefoneCelular;
    }

    public function setTelefoneCelular(?string $telefoneCelular): self
    {
        $this->telefoneCelular = $telefoneCelular;
        return $this;
    }

    public function getTelefoneFixo(): ?string
    {
        return $this->telefoneFixo;
    }

    public function setTelefoneFixo(?string $telefoneFixo): self
    {
        $this->telefoneFixo = $telefoneFixo;
        return $this;
    }

    public function getCep(): string
    {
        return $this->cep;
    }

    public function setCep(string $cep): self
    {
        $this->cep = $cep;
        return $this;
    }

    public function getEndereco(): string
    {
        return $this->endereco;
    }

    public function setEndereco(string $endereco): self
    {
        $this->endereco = $endereco;
        return $this;
    }

    public function getCidade(): string
    {
        return $this->cidade;
    }

    public function setCidade(string $cidade): self
    {
        $this->cidade = $cidade;
        return $this;
    }

    public function getEstado(): string
    {
        return $this->estado;
    }

    public function setEstado(string $estado): self
    {
        $this->estado = $estado;
        return $this;
    }

    public function getComplemento(): ?string
    {
        return $this->complemento;
    }

    public function setComplemento(?string $complemento): self
    {
        $this->complemento = $complemento;
        return $this;
    }

    public function getCriadoAt(): ?\DateTimeImmutable
    {
        return $this->criadoAt;
    }

    public function setCriadoAt(\DateTimeImmutable $criadoAt): self
    {
        $this->criadoAt = $criadoAt;
        return $this;
    }

    public function getModificadoEm(): ?\DateTimeImmutable
    {
        return $this->modificadoEm;
    }

    public function setModificadoEm(?\DateTimeImmutable $modificadoEm): self
    {
        $this->modificadoEm = $modificadoEm;
        return $this;
    }

    public function getCriadoPor(): ?User
    {
        return $this->criadoPor;
    }

    public function setCriadoPor(?User $criadoPor): self
    {
        $this->criadoPor = $criadoPor;
        return $this;
    }

    abstract public function getNomeExibicao(): string;

}
