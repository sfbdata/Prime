<?php

namespace App\Entity\Tarefa;

use App\Entity\Auth\User;
use App\Entity\Pasta\Pasta;
use App\Tarefa\Repository\TarefaRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TarefaRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Tarefa
{
    public const STATUS_PENDENTE    = 'pendente';
    public const STATUS_EM_REVISAO  = 'em_revisao';
    public const STATUS_CONCLUIDA   = 'concluida';

    public const STATUS_LABELS = [
        self::STATUS_PENDENTE   => 'Pendente',
        self::STATUS_EM_REVISAO => 'Para Revisão',
        self::STATUS_CONCLUIDA  => 'Concluída',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $titulo;

    #[ORM\Column(type: 'text')]
    private string $descricao;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $prazo = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDENTE;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $dataCriacao;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dataAlteracao = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dataConclusao = null;

    #[ORM\ManyToOne(targetEntity: Pasta::class, inversedBy: 'tarefas')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Pasta $pasta;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $criadoPor = null;

    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'tarefa_responsaveis')]
    private Collection $responsaveis;

    #[ORM\OneToMany(mappedBy: 'tarefa', targetEntity: TarefaMensagem::class, orphanRemoval: true, cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['criadoEm' => 'ASC'])]
    private Collection $mensagens;

    public function __construct()
    {
        $this->dataCriacao = new \DateTimeImmutable();
        $this->mensagens   = new ArrayCollection();
        $this->responsaveis = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function atualizarDataAlteracao(): void
    {
        $this->dataAlteracao = new \DateTimeImmutable();
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

    public function getDescricao(): string
    {
        return $this->descricao;
    }

    public function setDescricao(string $descricao): self
    {
        $this->descricao = $descricao;
        return $this;
    }

    public function getPrazo(): ?\DateTimeImmutable
    {
        return $this->prazo;
    }

    public function setPrazo(?\DateTimeImmutable $prazo): self
    {
        $this->prazo = $prazo;
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

    public function getDataCriacao(): \DateTimeImmutable
    {
        return $this->dataCriacao;
    }

    public function getDataAlteracao(): ?\DateTimeImmutable
    {
        return $this->dataAlteracao;
    }

    public function getDataConclusao(): ?\DateTimeImmutable
    {
        return $this->dataConclusao;
    }

    public function setDataConclusao(?\DateTimeImmutable $dataConclusao): self
    {
        $this->dataConclusao = $dataConclusao;
        return $this;
    }

    public function getPasta(): Pasta
    {
        return $this->pasta;
    }

    public function setPasta(Pasta $pasta): self
    {
        $this->pasta = $pasta;
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

    /**
     * @return Collection<int, User>
     */
    public function getResponsaveis(): Collection
    {
        return $this->responsaveis;
    }

    public function getResponsavel(): ?User
    {
        return $this->responsaveis->first() ?: null;
    }

    public function addResponsavel(User $responsavel): self
    {
        if (!$this->responsaveis->contains($responsavel)) {
            $this->responsaveis->add($responsavel);
        }
        return $this;
    }

    public function removeResponsavel(User $responsavel): self
    {
        $this->responsaveis->removeElement($responsavel);
        return $this;
    }

    /**
     * @return Collection<int, TarefaMensagem>
     */
    public function getMensagens(): Collection
    {
        return $this->mensagens;
    }

    public function addMensagem(TarefaMensagem $mensagem): self
    {
        if (!$this->mensagens->contains($mensagem)) {
            $this->mensagens->add($mensagem);
            $mensagem->setTarefa($this);
        }
        return $this;
    }

    public function removeMensagem(TarefaMensagem $mensagem): self
    {
        if ($this->mensagens->removeElement($mensagem)) {
            if ($mensagem->getTarefa() === $this) {
                $mensagem->setTarefa(null);
            }
        }
        return $this;
    }

    /**
     * @return array{dias: int|null, cor: string, texto: string}
     */
    public function getPrazoInfo(): array
    {
        if ($this->prazo === null || $this->status === self::STATUS_CONCLUIDA) {
            return ['dias' => null, 'cor' => '', 'texto' => '-'];
        }

        $hoje = new \DateTimeImmutable('today');
        $diff = $hoje->diff($this->prazo);
        $dias = (int) $diff->format('%r%a');

        if ($dias > 3) {
            $cor = 'success';
        } elseif ($dias >= 1) {
            $cor = 'warning';
        } else {
            $cor = 'danger';
        }

        if ($dias < 0) {
            $texto = abs($dias) . ' dia(s) atrasado';
        } elseif ($dias === 0) {
            $texto = 'Vence hoje';
        } elseif ($dias === 1) {
            $texto = '1 dia';
        } else {
            $texto = $dias . ' dias';
        }

        return ['dias' => $dias, 'cor' => $cor, 'texto' => $texto];
    }
}
