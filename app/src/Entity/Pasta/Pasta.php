<?php

declare(strict_types=1);

namespace App\Entity\Pasta;

use App\Entity\Auth\User;
use App\Cliente\Entity\Cliente;
use App\Expediente\Entity\Marcador;
use App\Processo\Entity\Processo;
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
    public const SITUACAO_ATIVA     = 'ativo';
    public const SITUACAO_ARQUIVADA = 'arquivado';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 50)]
    #[ORM\Column(length: 255, unique: true)]
    private ?string $nup = null;

    #[Assert\Choice(choices: [self::SITUACAO_ATIVA, self::SITUACAO_ARQUIVADA])]
    #[ORM\Column(length: 20, options: ['default' => self::SITUACAO_ATIVA])]
    private string $situacao = self::SITUACAO_ATIVA;

    #[ORM\Column(enumType: PrioridadePasta::class, options: ['default' => 'normal'])]
    private PrioridadePasta $prioridade = PrioridadePasta::Normal;

    #[Assert\NotNull]
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $dataAbertura;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nomeCliente = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nomeAcao = null;

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
    #[ORM\OrderBy(['ordem' => 'ASC'])]
    private Collection $documentos;

    #[ORM\OneToMany(mappedBy: 'pasta', targetEntity: Tarefa::class)]
    private Collection $tarefas;

    #[ORM\ManyToMany(targetEntity: Marcador::class)]
    #[ORM\JoinTable(name: 'pasta_marcador')]
    private Collection $marcadores;

    #[ORM\Column(length: 20, options: ['default' => 'PENDENTE'])]
    private string $situacaoContrato = 'PENDENTE';

    #[ORM\Column(options: ['default' => false])]
    private bool $proBono = false;

    #[ORM\OneToMany(mappedBy: 'pasta', targetEntity: PastaObservacaoFinanceira::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $observacoesFinanceiras;

    #[ORM\OneToMany(mappedBy: 'pasta', targetEntity: PastaObservacaoDetalhes::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $observacoesDetalhes;

    #[ORM\OneToMany(mappedBy: 'pasta', targetEntity: PastaChecklistItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['ordem' => 'ASC'])]
    private Collection $checklistItens;

    #[ORM\OneToMany(mappedBy: 'pasta', targetEntity: PastaSecao::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['ordem' => 'ASC'])]
    private Collection $secoes;

    public function __construct()
    {
        $this->dataAbertura = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
        $this->modificadoEm = new \DateTimeImmutable();
        $this->clientes = new ArrayCollection();
        $this->partesContrarias = new ArrayCollection();
        $this->documentos = new ArrayCollection();
        $this->tarefas = new ArrayCollection();
        $this->marcadores = new ArrayCollection();
        $this->observacoesFinanceiras = new ArrayCollection();
        $this->observacoesDetalhes = new ArrayCollection();
        $this->checklistItens = new ArrayCollection();
        $this->secoes = new ArrayCollection();
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

    public function getSituacao(): string
    {
        return $this->situacao;
    }

    public function setSituacao(string $situacao): self
    {
        $this->situacao = $situacao;

        return $this;
    }

    public function getPrioridade(): PrioridadePasta
    {
        return $this->prioridade;
    }

    public function setPrioridade(PrioridadePasta $prioridade): self
    {
        $this->prioridade = $prioridade;

        return $this;
    }

    public function getPrioridadeBadgeClass(): string
    {
        return match ($this->prioridade) {
            PrioridadePasta::Normal     => 'text-bg-secondary',
            PrioridadePasta::Prioridade => 'text-bg-warning',
            PrioridadePasta::Urgente    => 'text-bg-danger',
        };
    }

    public function getPrioridadeLabel(): string
    {
        return match ($this->prioridade) {
            PrioridadePasta::Normal     => 'Normal',
            PrioridadePasta::Prioridade => 'Prioridade',
            PrioridadePasta::Urgente    => 'Urgente',
        };
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

    public function getNomeCliente(): ?string
    {
        return $this->nomeCliente;
    }

    public function setNomeCliente(?string $nomeCliente): self
    {
        $this->nomeCliente = $nomeCliente;
        return $this;
    }

    public function getNomeAcao(): ?string
    {
        return $this->nomeAcao;
    }

    public function setNomeAcao(?string $nomeAcao): self
    {
        $this->nomeAcao = $nomeAcao;
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

    /** @return Collection<int, Marcador> */
    public function getMarcadores(): Collection
    {
        return $this->marcadores;
    }

    public function addMarcador(Marcador $marcador): self
    {
        foreach ($this->marcadores as $existente) {
            if ($existente->getId() === $marcador->getId()) {
                return $this;
            }
        }
        $this->marcadores->add($marcador);
        return $this;
    }

    public function removeMarcador(Marcador $marcador): self
    {
        foreach ($this->marcadores as $existente) {
            if ($existente->getId() === $marcador->getId()) {
                $this->marcadores->removeElement($existente);
                return $this;
            }
        }
        return $this;
    }

    public function hasMarcador(Marcador $marcador): bool
    {
        foreach ($this->marcadores as $existente) {
            if ($existente->getId() === $marcador->getId()) {
                return true;
            }
        }

        return false;
    }

    public function getSituacaoContrato(): string
    {
        return $this->situacaoContrato;
    }

    public function setSituacaoContrato(string $situacao): self
    {
        $this->situacaoContrato = $situacao;

        return $this;
    }

    public function isProBono(): bool
    {
        return $this->proBono;
    }

    public function setProBono(bool $proBono): self
    {
        $this->proBono = $proBono;

        return $this;
    }

    /** @return Collection<int, PastaObservacaoFinanceira> */
    public function getObservacoesFinanceiras(): Collection
    {
        return $this->observacoesFinanceiras;
    }

    /** @return Collection<int, PastaObservacaoDetalhes> */
    public function getObservacoesDetalhes(): Collection
    {
        return $this->observacoesDetalhes;
    }

    /** @return Collection<int, PastaChecklistItem> */
    public function getChecklistItens(): Collection
    {
        return $this->checklistItens;
    }

    /** @return Collection<int, PastaSecao> */
    public function getSecoes(): Collection
    {
        return $this->secoes;
    }

    public function addSecao(PastaSecao $secao): self
    {
        if (!$this->secoes->contains($secao)) {
            $this->secoes->add($secao);
            $secao->setPasta($this);
        }

        return $this;
    }

    public function removeSecao(PastaSecao $secao): self
    {
        if ($this->secoes->removeElement($secao)) {
            if ($secao->getPasta() === $this) {
                $secao->setPasta(null);
            }
        }

        return $this;
    }
}
