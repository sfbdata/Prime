<?php

namespace App\Entity\Comercial;

use App\Entity\Cliente\Cliente;
use App\Repository\PreCadastroRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PreCadastroRepository::class)]
#[ORM\HasLifecycleCallbacks]
class PreCadastro
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $nomeCliente;

    #[ORM\Column(length: 14)]
    private string $cpf;

    #[ORM\Column(length: 50)]
    private string $tipo; // PF/PJ - Empresa ou Condomínio/Associação

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telefone = null;

    #[ORM\Column(length: 255)]
    private string $areaDireito;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $prazo = null;

    #[ORM\Column(length: 20)]
    private string $natureza; // Consultivo ou Judicial

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $faseJudicial = null; // Inicial, Contestação ou Recurso

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $numeroProcesso = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $numeroContrato = null; // Novo campo adicionado

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $descricaoContrato = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $valorContrato = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $statusContrato = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $criadoAt = null; // Preenchido automaticamente

    #[ORM\OneToOne(targetEntity: Cliente::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Cliente $cliente = null;

    // 🔹 Lifecycle Callback para preencher criadoAt
    #[ORM\PrePersist]
    public function setCreatedAtValue(): void
    {
        $this->criadoAt = new \DateTimeImmutable();
    }

    // 🔹 Getters e Setters

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNomeCliente(): ?string
    {
        return $this->nomeCliente;
    }

    public function setNomeCliente(string $nomeCliente): self
    {
        $this->nomeCliente = $nomeCliente;
        return $this;
    }

    public function getCpf(): ?string
    {
        return $this->cpf;
    }

    public function setCpf(string $cpf): self
    {
        $this->cpf = $cpf;
        return $this;
    }

    public function getTipo(): ?string
    {
        return $this->tipo;
    }

    public function setTipo(string $tipo): self
    {
        $this->tipo = $tipo;
        return $this;
    }

    public function getTelefone(): ?string
    {
        return $this->telefone;
    }

    public function setTelefone(?string $telefone): self
    {
        $this->telefone = $telefone;
        return $this;
    }

    public function getAreaDireito(): ?string
    {
        return $this->areaDireito;
    }

    public function setAreaDireito(string $areaDireito): self
    {
        $this->areaDireito = $areaDireito;
        return $this;
    }

    public function getPrazo(): ?\DateTimeInterface
    {
        return $this->prazo;
    }

    public function setPrazo(?\DateTimeInterface $prazo): self
    {
        $this->prazo = $prazo;
        return $this;
    }

    public function getNatureza(): ?string
    {
        return $this->natureza;
    }

    public function setNatureza(string $natureza): self
    {
        $this->natureza = $natureza;
        return $this;
    }

    public function getFaseJudicial(): ?string
    {
        return $this->faseJudicial;
    }

    public function setFaseJudicial(?string $faseJudicial): self
    {
        $this->faseJudicial = $faseJudicial;
        return $this;
    }

    public function getNumeroProcesso(): ?string
    {
        return $this->numeroProcesso;
    }

    public function setNumeroProcesso(?string $numeroProcesso): self
    {
        $this->numeroProcesso = $numeroProcesso;
        return $this;
    }

    public function getNumeroContrato(): ?string
    {
        return $this->numeroContrato;
    }

    public function setNumeroContrato(?string $numeroContrato): self
    {
        $this->numeroContrato = $numeroContrato;
        return $this;
    }

    public function getDescricaoContrato(): ?string
    {
        return $this->descricaoContrato;
    }

    public function setDescricaoContrato(?string $descricaoContrato): self
    {
        $this->descricaoContrato = $descricaoContrato;
        return $this;
    }

    public function getValorContrato(): ?string
    {
        return $this->valorContrato;
    }

    public function setValorContrato(?string $valorContrato): self
    {
        $this->valorContrato = $valorContrato;
        return $this;
    }

    public function getStatusContrato(): ?string
    {
        return $this->statusContrato;
    }

    public function getStatusContratoCentralizado(): string
    {
        if ($this->cliente instanceof Cliente) {
            return $this->cliente->getStatusContratoCentralizado();
        }

        if ($this->statusContrato !== null && $this->statusContrato !== '') {
            return $this->statusContrato;
        }

        return 'SEM_CONTRATO';
    }

    public function setStatusContrato(?string $statusContrato): self
    {
        $this->statusContrato = $statusContrato;
        return $this;
    }

    public function getCriadoAt(): ?\DateTimeInterface
    {
        return $this->criadoAt;
    }

    public function setCriadoAt(\DateTimeInterface $criadoAt): self
    {
        $this->criadoAt = $criadoAt;
        return $this;
    }

    public function getCliente(): ?Cliente
    {
        return $this->cliente;
    }

    public function setCliente(?Cliente $cliente): self
    {
        $this->cliente = $cliente;
        return $this;
    }
}
