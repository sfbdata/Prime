<?php

namespace App\Entity\ServiceDesk;

use App\Entity\Auth\User;
use App\Repository\ChamadoInteracaoRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ChamadoInteracaoRepository::class)]
class ChamadoInteracao
{
    // Tipos de interação
    public const TIPO_COMENTARIO = 'comentario';
    public const TIPO_RESPOSTA = 'resposta';
    public const TIPO_STATUS = 'status';
    public const TIPO_ATRIBUICAO = 'atribuicao';
    public const TIPO_SISTEMA = 'sistema';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Chamado::class, inversedBy: 'interacoes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Chamado $chamado = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $autor = null;

    #[ORM\Column(type: 'text')]
    private string $mensagem = '';

    #[ORM\Column(length: 30)]
    private string $tipo = self::TIPO_COMENTARIO;

    #[ORM\Column(type: 'boolean')]
    private bool $interno = false; // Se true, só visível para equipe TI

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    public function __construct()
    {
        $this->criadoEm = new \DateTimeImmutable();
    }

    // Getters e Setters

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChamado(): ?Chamado
    {
        return $this->chamado;
    }

    public function setChamado(?Chamado $chamado): self
    {
        $this->chamado = $chamado;
        return $this;
    }

    public function getAutor(): ?User
    {
        return $this->autor;
    }

    public function setAutor(User $autor): self
    {
        $this->autor = $autor;
        return $this;
    }

    public function getMensagem(): string
    {
        return $this->mensagem;
    }

    public function setMensagem(string $mensagem): self
    {
        $this->mensagem = $mensagem;
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

    public function isInterno(): bool
    {
        return $this->interno;
    }

    public function setInterno(bool $interno): self
    {
        $this->interno = $interno;
        return $this;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    // Métodos auxiliares

    public function getTipoLabel(): string
    {
        return match ($this->tipo) {
            self::TIPO_COMENTARIO => 'Comentário',
            self::TIPO_RESPOSTA => 'Resposta',
            self::TIPO_STATUS => 'Alteração de Status',
            self::TIPO_ATRIBUICAO => 'Atribuição',
            self::TIPO_SISTEMA => 'Sistema',
            default => $this->tipo,
        };
    }

    public function getIcone(): string
    {
        return match ($this->tipo) {
            self::TIPO_COMENTARIO => 'bi-chat-text',
            self::TIPO_RESPOSTA => 'bi-reply',
            self::TIPO_STATUS => 'bi-arrow-repeat',
            self::TIPO_ATRIBUICAO => 'bi-person-check',
            self::TIPO_SISTEMA => 'bi-gear',
            default => 'bi-chat',
        };
    }

    public function getBadgeClass(): string
    {
        return match ($this->tipo) {
            self::TIPO_COMENTARIO => 'bg-primary',
            self::TIPO_RESPOSTA => 'bg-success',
            self::TIPO_STATUS => 'bg-info',
            self::TIPO_ATRIBUICAO => 'bg-warning text-dark',
            self::TIPO_SISTEMA => 'bg-secondary',
            default => 'bg-secondary',
        };
    }
}
