<?php

namespace App\Entity\Pasta;

use App\Entity\Auth\User;
use App\Entity\Cliente\Cliente;
use App\Entity\Processo\Processo;
use App\Entity\Tarefa\Tarefa;
use App\Repository\PastaRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PastaRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Pasta
{
    public const STATUS_ATIVO = 'ativo';
    public const STATUS_ARQUIVADO = 'arquivado';

    public const STATUS_DOCUMENTOS_PENDENTE = 'PENDENTE_DE_DOCUMENTACAO';
    public const STATUS_DOCUMENTOS_APTO = 'APTO_PARA_PROTOCOLAR';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 50)]
    #[ORM\Column(length: 255, unique: true)]
    private ?string $nup = null;

    #[Assert\Choice(choices: [self::STATUS_ATIVO, self::STATUS_ARQUIVADO])]
    #[ORM\Column(length: 20, options: ['default' => self::STATUS_ATIVO])]
    private string $status = self::STATUS_ATIVO;

    #[Assert\NotNull]
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $dataAbertura;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $descricao = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $modificadoEm = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $criadoPor = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $responsavel = null;

    #[ORM\ManyToOne(targetEntity: Processo::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Processo $processo = null;


    #[ORM\ManyToMany(targetEntity: Cliente::class)]
    #[ORM\JoinTable(name: 'pasta_cliente')]
    private Collection $clientes;

    #[ORM\OneToMany(mappedBy: 'pasta', targetEntity: ParteContraria::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $partesContrarias;

    #[ORM\OneToMany(mappedBy: 'pasta', targetEntity: PastaDocumento::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $documentos;

    #[ORM\OneToMany(mappedBy: 'pasta', targetEntity: Tarefa::class)]
    private Collection $tarefas;

    #[ORM\Column(options: ['default' => false])]
    private bool $docPecaOk = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $docProcuracaoOk = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $docIdentificacaoOk = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $docComprovanteResidenciaOk = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $docGratuidadeJusticaOk = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $docDemaisOk = false;

    #[ORM\Column(length: 40)]
    private string $statusDocumentos = self::STATUS_DOCUMENTOS_PENDENTE;

    public function __construct()
    {
        $this->dataAbertura = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
        $this->modificadoEm = new \DateTimeImmutable();
        $this->clientes = new ArrayCollection();
        $this->partesContrarias = new ArrayCollection();
        $this->documentos = new ArrayCollection();
        $this->tarefas = new ArrayCollection();
        $this->statusDocumentos = self::STATUS_DOCUMENTOS_PENDENTE;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNup(): ?string
    {
        return $this->nup;
    }

    public function setNup(string $nup): self
    {
        $this->nup = $nup;
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

    public function getDataAbertura(): \DateTimeImmutable
    {
        return $this->dataAbertura;
    }

    public function setDataAbertura(\DateTimeImmutable $dataAbertura): self
    {
        $this->dataAbertura = $dataAbertura;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    #[ORM\PreUpdate]
    public function setModificadoEmValue(): void
    {
        $this->modificadoEm = new \DateTimeImmutable();
    }

    public function getModificadoEm(): ?\DateTimeImmutable
    {
        return $this->modificadoEm;
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

    public function getResponsavel(): ?User
    {
        return $this->responsavel;
    }

    public function setResponsavel(?User $responsavel): self
    {
        $this->responsavel = $responsavel;
        return $this;
    }

    public function getProcesso(): ?Processo
    {
        return $this->processo;
    }

    public function setProcesso(?Processo $processo): self
    {
        $this->processo = $processo;
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
     * @return Collection<int, ParteContraria>
     */
    public function getPartesContrarias(): Collection
    {
        return $this->partesContrarias;
    }

    public function addParteContraria(ParteContraria $parteContraria): self
    {
        if (!$this->partesContrarias->contains($parteContraria)) {
            $this->partesContrarias->add($parteContraria);
            $parteContraria->setPasta($this);
        }
        return $this;
    }

    public function removeParteContraria(ParteContraria $parteContraria): self
    {
        if ($this->partesContrarias->removeElement($parteContraria)) {
            if ($parteContraria->getPasta() === $this) {
                $parteContraria->setPasta(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, PastaDocumento>
     */
    public function getDocumentos(): Collection
    {
        return $this->documentos;
    }

    /**
     * @return Collection<int, Tarefa>
     */
    public function getTarefas(): Collection
    {
        return $this->tarefas;
    }

    public function addDocumento(PastaDocumento $documento): self
    {
        if (!$this->documentos->contains($documento)) {
            $this->documentos->add($documento);
            $documento->setPasta($this);
        }
        return $this;
    }

    public function removeDocumento(PastaDocumento $documento): self
    {
        if ($this->documentos->removeElement($documento)) {
            if ($documento->getPasta() === $this) {
                $documento->setPasta(null);
            }
        }
        return $this;
    }

    public function isDocPecaOk(): bool { return $this->docPecaOk; }
    public function setDocPecaOk(bool $v): self { $this->docPecaOk = $v; $this->recalculateStatusDocumentos(); return $this; }

    public function isDocProcuracaoOk(): bool { return $this->docProcuracaoOk; }
    public function setDocProcuracaoOk(bool $v): self { $this->docProcuracaoOk = $v; $this->recalculateStatusDocumentos(); return $this; }

    public function isDocIdentificacaoOk(): bool { return $this->docIdentificacaoOk; }
    public function setDocIdentificacaoOk(bool $v): self { $this->docIdentificacaoOk = $v; $this->recalculateStatusDocumentos(); return $this; }

    public function isDocComprovanteResidenciaOk(): bool { return $this->docComprovanteResidenciaOk; }
    public function setDocComprovanteResidenciaOk(bool $v): self { $this->docComprovanteResidenciaOk = $v; $this->recalculateStatusDocumentos(); return $this; }

    public function isDocGratuidadeJusticaOk(): bool { return $this->docGratuidadeJusticaOk; }
    public function setDocGratuidadeJusticaOk(bool $v): self { $this->docGratuidadeJusticaOk = $v; $this->recalculateStatusDocumentos(); return $this; }

    public function isDocDemaisOk(): bool { return $this->docDemaisOk; }
    public function setDocDemaisOk(bool $v): self { $this->docDemaisOk = $v; $this->recalculateStatusDocumentos(); return $this; }

    public function getStatusDocumentos(): string { return $this->statusDocumentos; }

    private function recalculateStatusDocumentos(): void
    {
        $this->statusDocumentos = ($this->docPecaOk && $this->docProcuracaoOk && $this->docIdentificacaoOk
            && $this->docComprovanteResidenciaOk && $this->docGratuidadeJusticaOk && $this->docDemaisOk)
            ? self::STATUS_DOCUMENTOS_APTO
            : self::STATUS_DOCUMENTOS_PENDENTE;
    }
}
