<?php

namespace App\Entity;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Repository\NotificacaoRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NotificacaoRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Notificacao
{
    public const TIPO_TAREFA_CRIADA = 'tarefa_criada';
    public const TIPO_TAREFA_EM_REVISAO = 'tarefa_em_revisao';
    public const TIPO_TAREFA_PENDENTE = 'tarefa_pendente';
    public const TIPO_TAREFA_CONCLUIDA = 'tarefa_concluida';
    public const TIPO_EVENTO_CRIADO = 'evento_criado';
    public const TIPO_EVENTO_CANCELADO = 'evento_cancelado';
    public const TIPO_SOLICITACAO_ACESSO = 'solicitacao_acesso';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $usuario = null;

    #[ORM\Column(length: 50)]
    private string $tipo;

    #[ORM\Column(length: 255)]
    private string $titulo;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $mensagem = null;

    #[ORM\ManyToOne(targetEntity: Tarefa::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Tarefa $tarefa = null;

    #[ORM\Column(type: 'boolean')]
    private bool $lida = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadaEm;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lidaEm = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $url = null;

    public function __construct()
    {
        $this->criadaEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsuario(): ?User
    {
        return $this->usuario;
    }

    public function setUsuario(?User $usuario): self
    {
        $this->usuario = $usuario;
        return $this;
    }

    public function getTipo(): string
    {
        return $this->tipo;
    }

    public function setTipo(string $tipo): self
    {
        $this->tipo = $tipo;
        return $this;
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

    public function getMensagem(): ?string
    {
        return $this->mensagem;
    }

    public function setMensagem(?string $mensagem): self
    {
        $this->mensagem = $mensagem;
        return $this;
    }

    public function getTarefa(): ?Tarefa
    {
        return $this->tarefa;
    }

    public function setTarefa(?Tarefa $tarefa): self
    {
        $this->tarefa = $tarefa;
        return $this;
    }

    public function isLida(): bool
    {
        return $this->lida;
    }

    public function setLida(bool $lida): self
    {
        $this->lida = $lida;
        if ($lida && $this->lidaEm === null) {
            $this->lidaEm = new \DateTimeImmutable();
        }
        return $this;
    }

    public function getCriadaEm(): \DateTimeImmutable
    {
        return $this->criadaEm;
    }

    public function getLidaEm(): ?\DateTimeImmutable
    {
        return $this->lidaEm;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): self
    {
        $this->url = $url;
        return $this;
    }

    public function getIcone(): string
    {
        return match ($this->tipo) {
            self::TIPO_TAREFA_CRIADA => 'bi-plus-circle text-primary',
            self::TIPO_TAREFA_EM_REVISAO => 'bi-hourglass-split text-warning',
            self::TIPO_TAREFA_PENDENTE => 'bi-arrow-repeat text-info',
            self::TIPO_TAREFA_CONCLUIDA => 'bi-check-circle text-success',
            self::TIPO_EVENTO_CRIADO => 'bi-calendar-plus text-primary',
            self::TIPO_EVENTO_CANCELADO => 'bi-calendar-x text-danger',
            self::TIPO_SOLICITACAO_ACESSO => 'bi-key text-warning',
            default => 'bi-bell text-secondary',
        };
    }

    public function getTempoRelativo(): string
    {
        $agora = new \DateTimeImmutable();
        $diff = $agora->diff($this->criadaEm);

        if ($diff->days === 0) {
            if ($diff->h === 0) {
                if ($diff->i === 0) {
                    return 'agora';
                }
                return $diff->i . ' min atrás';
            }
            return $diff->h . 'h atrás';
        }

        if ($diff->days === 1) {
            return 'ontem';
        }

        if ($diff->days < 7) {
            return $diff->days . ' dias atrás';
        }

        return $this->criadaEm->format('d/m/Y');
    }
}
