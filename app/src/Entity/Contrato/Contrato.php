<?php

namespace App\Entity\Contrato;

use App\Entity\Auth\User;
use App\Entity\Cliente\Cliente;
use App\Entity\Processo\Processo;
use App\Repository\ContratoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContratoRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Contrato
{
    public const STATUS_ATIVO = 'ATIVO';
    public const STATUS_ENCERRADO = 'ENCERRADO';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $titulo;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $descricao = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_ATIVO;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dataInicio = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private ?string $valorTotal = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $criadoAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $modificadoEm = null;

    #[ORM\ManyToMany(targetEntity: Cliente::class, mappedBy: 'contratos')]
    private Collection $clientes;

    #[ORM\OneToMany(mappedBy: 'contrato', targetEntity: Processo::class, orphanRemoval: true)]
    private Collection $processos;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $responsavel = null;

    public function __construct()
    {
        $this->clientes = new ArrayCollection();
        $this->processos = new ArrayCollection();
        $now = new \DateTimeImmutable();
        $this->criadoAt = $now;
        $this->modificadoEm = $now;
        $this->status = self::STATUS_ATIVO;
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

    public function getTitulo(): string
    {
        return $this->titulo;
    }

    public function setTitulo(string $titulo): self
    {
        $this->titulo = $titulo;
        return $this;
    }

    public function getDescricao(): ?string
    {
        return $this->descricao;
    }

    public function setDescricao(?string $descricao): self
    {
        $this->descricao = $descricao;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getDataInicio(): ?\DateTimeImmutable
    {
        return $this->dataInicio;
    }

    public function setDataInicio(?\DateTimeImmutable $dataInicio): self
    {
        $this->dataInicio = $dataInicio;
        return $this;
    }

    public function getValorTotal(): ?string
    {
        return $this->valorTotal;
    }

    public function setValorTotal(?string $valorTotal): self
    {
        $this->valorTotal = $valorTotal;
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

    /**
     * @return Collection<int, Cliente>
     */
    public function getClientes(): Collection
    {
        return $this->clientes;
    }

    public function addCliente(Cliente $cliente): self
    {
        if (!$this->clientes->contains($cliente)) {
            $this->clientes->add($cliente);
        }

        return $this;
    }

    public function removeCliente(Cliente $cliente): self
    {
        $this->clientes->removeElement($cliente);

        return $this;
    }

    /**
     * @return Collection<int, Processo>
     */
    public function getProcessos(): Collection
    {
        return $this->processos;
    }

    public function addProcesso(Processo $processo): self
    {
        if (!$this->processos->contains($processo)) {
            $this->processos->add($processo);
            $processo->setContrato($this);
        }

        return $this;
    }

    public function removeProcesso(Processo $processo): self
    {
        if ($this->processos->removeElement($processo)) {
            if ($processo->getContrato() === $this) {
                $processo->setContrato(null);
            }
        }

        return $this;
    }

    public function getResponsavel(): ?User
    {
        return $this->responsavel;
    }

    public function setResponsavel(?User $responsavel): self
    {
        $this->responsavel = $responsavel;
        return $this;
    }
}
