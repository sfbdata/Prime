<?php

namespace App\Entity\Cliente;

use App\Entity\Contrato\Contrato;
use App\Repository\ClienteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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

    #[ORM\ManyToMany(targetEntity: Contrato::class, inversedBy: 'clientes', cascade: ['persist'])]
    #[ORM\JoinTable(name: 'cliente_contrato')]
    private Collection $contratos;

    public function __construct()
    {
        $this->criadoAt = new \DateTimeImmutable();
        $this->modificadoEm = new \DateTimeImmutable();
        $this->contratos = new ArrayCollection();
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

    public function isContratoEnviado(): bool
    {
        return !$this->contratos->isEmpty();
    }

    public function getStatusContratoCentralizado(): string
    {
        if ($this->contratos->isEmpty()) {
            return 'SEM_CONTRATO';
        }

        foreach ($this->contratos as $contrato) {
            if ($contrato->getStatus() === Contrato::STATUS_ATIVO) {
                return Contrato::STATUS_ATIVO;
            }
        }

        return 'INATIVO';
    }

    /**
     * @return Collection<int, Contrato>
     */
    public function getContratos(): Collection
    {
        return $this->contratos;
    }

    public function addContrato(Contrato $contrato): self
    {
        if (!$this->contratos->contains($contrato)) {
            $this->contratos->add($contrato);
            $contrato->addCliente($this);
        }

        return $this;
    }

    public function removeContrato(Contrato $contrato): self
    {
        if ($this->contratos->removeElement($contrato)) {
            $contrato->removeCliente($this);
        }

        return $this;
    }
}
