<?php

namespace App\Entity\Tarefa;

use App\Entity\Processo\Processo;
use App\Repository\TarefaRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TarefaRepository::class)]
class Tarefa
{
    public const STATUS_PENDENTE = 'pendente';
    public const STATUS_EM_REVISAO = 'em_revisao';
    public const STATUS_CONCLUIDA = 'concluida';
    public const STATUS_REABERTA = 'reaberta';

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
    private ?\DateTimeImmutable $dataConclusao = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $arquivosAdmin = [];

    #[ORM\ManyToOne(targetEntity: Processo::class, inversedBy: 'tarefas')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Processo $processo = null;

    #[ORM\OneToMany(mappedBy: 'tarefa', targetEntity: AtribuicaoTarefa::class, orphanRemoval: true, cascade: ['persist', 'remove'])]
    private Collection $atribuicoes;

    #[ORM\OneToMany(mappedBy: 'tarefa', targetEntity: TarefaMensagem::class, orphanRemoval: true, cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['criadoEm' => 'ASC'])]
    private Collection $mensagens;

    public function __construct()
    {
        $this->dataCriacao = new \DateTimeImmutable();
        $this->atribuicoes = new ArrayCollection();
        $this->mensagens = new ArrayCollection();
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

    public function setDataCriacao(\DateTimeImmutable $dataCriacao): self
    {
        $this->dataCriacao = $dataCriacao;
        return $this;
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

    public function getArquivosAdmin(): array
    {
        return $this->arquivosAdmin ?? [];
    }

    public function setArquivosAdmin(?array $arquivosAdmin): self
    {
        $this->arquivosAdmin = $arquivosAdmin ?? [];
        return $this;
    }

    public function addArquivoAdmin(string $arquivo): self
    {
        $arquivos = $this->getArquivosAdmin();
        $arquivos[] = $arquivo;
        $this->arquivosAdmin = $arquivos;

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
     * @return Collection<int, AtribuicaoTarefa>
     */
    public function getAtribuicoes(): Collection
    {
        return $this->atribuicoes;
    }

    public function addAtribuicao(AtribuicaoTarefa $atribuicao): self
    {
        if (!$this->atribuicoes->contains($atribuicao)) {
            $this->atribuicoes->add($atribuicao);
            $atribuicao->setTarefa($this);
        }

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

    public function removeAtribuicao(AtribuicaoTarefa $atribuicao): self
    {
        if ($this->atribuicoes->removeElement($atribuicao)) {
            if ($atribuicao->getTarefa() === $this) {
                $atribuicao->setTarefa(null);
            }
        }

        return $this;
    }

    public function getTempoTotalSegundos(): ?int
    {
        $inicio = null;
        $fim = $this->dataConclusao;

        foreach ($this->atribuicoes as $atribuicao) {
            $dataAtribuicao = $atribuicao->getDataAtribuicao();
            if ($inicio === null || $dataAtribuicao < $inicio) {
                $inicio = $dataAtribuicao;
            }

            if ($fim === null && $atribuicao->getDataEnvioRevisao() !== null) {
                $fim = $atribuicao->getDataEnvioRevisao();
            }
        }

        if ($inicio === null || $fim === null) {
            return null;
        }

        return max(0, $fim->getTimestamp() - $inicio->getTimestamp());
    }

    /**
     * Retorna informações sobre o prazo da tarefa
     * 
     * @return array{dias: int|null, cor: string, texto: string}
     */
    public function getPrazoInfo(): array
    {
        if ($this->prazo === null || $this->status === self::STATUS_CONCLUIDA) {
            return [
                'dias' => null,
                'cor' => '',
                'texto' => '-',
            ];
        }
        
        $hoje = new \DateTimeImmutable('today');
        $diff = $hoje->diff($this->prazo);
        $dias = (int) $diff->format('%r%a');
        
        // Determinar cor
        if ($dias > 3) {
            $cor = 'success'; // verde
        } elseif ($dias >= 1) {
            $cor = 'warning'; // amarelo
        } else {
            $cor = 'danger'; // vermelho (inclui 0 e negativos)
        }
        
        // Determinar texto
        if ($dias < 0) {
            $texto = abs($dias) . ' dia(s) atrasado';
        } elseif ($dias === 0) {
            $texto = 'Vence hoje';
        } elseif ($dias === 1) {
            $texto = '1 dia';
        } else {
            $texto = $dias . ' dias';
        }
        
        return [
            'dias' => $dias,
            'cor' => $cor,
            'texto' => $texto,
        ];
    }
}
