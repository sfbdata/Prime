<?php

namespace App\Entity\ServiceDesk;

use App\Entity\Auth\User;
use App\Repository\ChamadoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ChamadoRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Chamado
{
    // Status
    public const STATUS_ABERTO = 'aberto';
    public const STATUS_EM_ANDAMENTO = 'em_andamento';
    public const STATUS_RESOLVIDO = 'resolvido';
    public const STATUS_FECHADO = 'fechado';

    // Categorias
    public const CATEGORIA_SOFTWARE = 'software';
    public const CATEGORIA_HARDWARE = 'hardware';
    public const CATEGORIA_IMPRESSORA = 'impressora';
    public const CATEGORIA_REDE = 'rede';
    public const CATEGORIA_ACESSO = 'acesso';
    public const CATEGORIA_EMAIL = 'email';
    public const CATEGORIA_OUTROS = 'outros';

    // Prioridades
    public const PRIORIDADE_BAIXA = 'baixa';
    public const PRIORIDADE_MEDIA = 'media';
    public const PRIORIDADE_ALTA = 'alta';
    public const PRIORIDADE_CRITICA = 'critica';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $titulo = '';

    #[ORM\Column(type: 'text')]
    private string $descricao = '';

    #[ORM\Column(length: 50)]
    private string $categoria = self::CATEGORIA_OUTROS;

    #[ORM\Column(length: 20)]
    private string $prioridade = self::PRIORIDADE_MEDIA;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_ABERTO;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $departamento = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $solicitante = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $responsavel = null;

    #[ORM\OneToMany(mappedBy: 'chamado', targetEntity: ChamadoInteracao::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['criadoEm' => 'ASC'])]
    private Collection $interacoes;

    #[ORM\OneToMany(mappedBy: 'chamado', targetEntity: ChamadoAnexo::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $anexos;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $criadoEm = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $atualizadoEm = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $resolvidoEm = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $fechadoEm = null;

    public function __construct()
    {
        $this->criadoEm = new \DateTimeImmutable();
        $this->interacoes = new ArrayCollection();
        $this->anexos = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function setAtualizadoEmValue(): void
    {
        $this->atualizadoEm = new \DateTimeImmutable();
    }

    // Getters e Setters

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

    public function getDescricao(): string
    {
        return $this->descricao;
    }

    public function setDescricao(string $descricao): self
    {
        $this->descricao = $descricao;
        return $this;
    }

    public function getCategoria(): string
    {
        return $this->categoria;
    }

    public function setCategoria(string $categoria): self
    {
        $this->categoria = $categoria;
        return $this;
    }

    public function getPrioridade(): string
    {
        return $this->prioridade;
    }

    public function setPrioridade(string $prioridade): self
    {
        $this->prioridade = $prioridade;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        // Registrar datas automaticamente
        if ($status === self::STATUS_RESOLVIDO && $this->resolvidoEm === null) {
            $this->resolvidoEm = new \DateTimeImmutable();
        }
        if ($status === self::STATUS_FECHADO && $this->fechadoEm === null) {
            $this->fechadoEm = new \DateTimeImmutable();
        }

        return $this;
    }

    public function getDepartamento(): ?string
    {
        return $this->departamento;
    }

    public function setDepartamento(?string $departamento): self
    {
        $this->departamento = $departamento;
        return $this;
    }

    public function getSolicitante(): ?User
    {
        return $this->solicitante;
    }

    public function setSolicitante(User $solicitante): self
    {
        $this->solicitante = $solicitante;
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

    /**
     * @return Collection<int, ChamadoInteracao>
     */
    public function getInteracoes(): Collection
    {
        return $this->interacoes;
    }

    public function addInteracao(ChamadoInteracao $interacao): self
    {
        if (!$this->interacoes->contains($interacao)) {
            $this->interacoes->add($interacao);
            $interacao->setChamado($this);
        }
        return $this;
    }

    public function removeInteracao(ChamadoInteracao $interacao): self
    {
        if ($this->interacoes->removeElement($interacao)) {
            if ($interacao->getChamado() === $this) {
                $interacao->setChamado(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, ChamadoAnexo>
     */
    public function getAnexos(): Collection
    {
        return $this->anexos;
    }

    public function addAnexo(ChamadoAnexo $anexo): self
    {
        if (!$this->anexos->contains($anexo)) {
            $this->anexos->add($anexo);
            $anexo->setChamado($this);
        }
        return $this;
    }

    public function removeAnexo(ChamadoAnexo $anexo): self
    {
        if ($this->anexos->removeElement($anexo)) {
            if ($anexo->getChamado() === $this) {
                $anexo->setChamado(null);
            }
        }
        return $this;
    }

    public function getCriadoEm(): ?\DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getAtualizadoEm(): ?\DateTimeImmutable
    {
        return $this->atualizadoEm;
    }

    public function getResolvidoEm(): ?\DateTimeImmutable
    {
        return $this->resolvidoEm;
    }

    public function getFechadoEm(): ?\DateTimeImmutable
    {
        return $this->fechadoEm;
    }

    // Métodos auxiliares

    public function getCategoriaLabel(): string
    {
        return match ($this->categoria) {
            self::CATEGORIA_SOFTWARE => 'Software',
            self::CATEGORIA_HARDWARE => 'Hardware',
            self::CATEGORIA_IMPRESSORA => 'Impressora',
            self::CATEGORIA_REDE => 'Rede',
            self::CATEGORIA_ACESSO => 'Acesso/Permissão',
            self::CATEGORIA_EMAIL => 'E-mail',
            self::CATEGORIA_OUTROS => 'Outros',
            default => $this->categoria,
        };
    }

    public function getCategoriaIcone(): string
    {
        return match ($this->categoria) {
            self::CATEGORIA_SOFTWARE => 'bi bi-pc-display',
            self::CATEGORIA_HARDWARE => 'bi bi-motherboard',
            self::CATEGORIA_IMPRESSORA => 'bi bi-printer',
            self::CATEGORIA_REDE => 'bi bi-wifi',
            self::CATEGORIA_ACESSO => 'bi bi-key',
            self::CATEGORIA_EMAIL => 'bi bi-envelope',
            self::CATEGORIA_OUTROS => 'bi bi-question-circle',
            default => 'bi bi-tag',
        };
    }

    public function getCategoriaBadgeClass(): string
    {
        return match ($this->categoria) {
            self::CATEGORIA_SOFTWARE => 'bg-primary',
            self::CATEGORIA_HARDWARE => 'bg-secondary',
            self::CATEGORIA_IMPRESSORA => 'bg-info',
            self::CATEGORIA_REDE => 'bg-dark',
            self::CATEGORIA_ACESSO => 'bg-warning text-dark',
            self::CATEGORIA_EMAIL => 'bg-info',
            self::CATEGORIA_OUTROS => 'bg-secondary',
            default => 'bg-secondary',
        };
    }

    public function getPrioridadeLabel(): string
    {
        return match ($this->prioridade) {
            self::PRIORIDADE_BAIXA => 'Baixa',
            self::PRIORIDADE_MEDIA => 'Média',
            self::PRIORIDADE_ALTA => 'Alta',
            self::PRIORIDADE_CRITICA => 'Crítica',
            default => $this->prioridade,
        };
    }

    public function getPrioridadeBadgeClass(): string
    {
        return match ($this->prioridade) {
            self::PRIORIDADE_BAIXA => 'bg-success',
            self::PRIORIDADE_MEDIA => 'bg-info',
            self::PRIORIDADE_ALTA => 'bg-warning text-dark',
            self::PRIORIDADE_CRITICA => 'bg-danger',
            default => 'bg-secondary',
        };
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_ABERTO => 'Aberto',
            self::STATUS_EM_ANDAMENTO => 'Em Andamento',
            self::STATUS_RESOLVIDO => 'Resolvido',
            self::STATUS_FECHADO => 'Fechado',
            default => $this->status,
        };
    }

    public function getStatusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_ABERTO => 'bg-primary',
            self::STATUS_EM_ANDAMENTO => 'bg-warning text-dark',
            self::STATUS_RESOLVIDO => 'bg-success',
            self::STATUS_FECHADO => 'bg-secondary',
            default => 'bg-secondary',
        };
    }

    /**
     * Calcula o tempo de resolução em horas
     */
    public function getTempoResolucaoHoras(): ?float
    {
        if ($this->resolvidoEm === null || $this->criadoEm === null) {
            return null;
        }

        $diff = $this->criadoEm->diff($this->resolvidoEm);
        return ($diff->days * 24) + $diff->h + ($diff->i / 60);
    }

    /**
     * Verifica se o chamado está aberto
     */
    public function isAberto(): bool
    {
        return $this->status === self::STATUS_ABERTO;
    }

    /**
     * Verifica se o chamado pode ser editado pelo solicitante
     */
    public function podeSerEditadoPorSolicitante(): bool
    {
        return $this->status === self::STATUS_ABERTO;
    }

    /**
     * Verifica se o chamado pode ser fechado pelo solicitante
     */
    public function podeSerFechadoPorSolicitante(): bool
    {
        return $this->status === self::STATUS_RESOLVIDO;
    }
}
