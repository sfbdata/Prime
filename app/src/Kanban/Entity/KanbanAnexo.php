<?php

declare(strict_types=1);

namespace App\Kanban\Entity;

use App\Entity\Auth\User;
use App\Kanban\Repository\KanbanAnexoRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: KanbanAnexoRepository::class)]
#[ORM\Table(name: 'kanban_anexo')]
#[ORM\HasLifecycleCallbacks]
class KanbanAnexo
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 500)]
    private string $nomeOriginal;

    #[ORM\Column(length: 500)]
    private string $caminho;

    #[ORM\Column(type: 'integer')]
    private int $tamanho;

    #[ORM\Column(length: 255)]
    private string $mimeType;

    #[ORM\ManyToOne(inversedBy: 'anexos')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?KanbanCard $card = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $criadoPor = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $criadoEm = null;

    public function __construct(
        string $nomeOriginal,
        string $caminho,
        int $tamanho,
        string $mimeType,
        KanbanCard $card,
        User $criadoPor
    ) {
        $this->nomeOriginal = $nomeOriginal;
        $this->caminho      = $caminho;
        $this->tamanho      = $tamanho;
        $this->mimeType     = $mimeType;
        $this->card         = $card;
        $this->criadoPor    = $criadoPor;
    }

    #[ORM\PrePersist]
    public function aoPersistir(): void
    {
        $this->criadoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNomeOriginal(): string
    {
        return $this->nomeOriginal;
    }

    public function getCaminho(): string
    {
        return $this->caminho;
    }

    public function getTamanho(): int
    {
        return $this->tamanho;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getCard(): ?KanbanCard
    {
        return $this->card;
    }

    public function getCriadoPor(): ?User
    {
        return $this->criadoPor;
    }

    public function getCriadoEm(): ?\DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function isImagem(): bool
    {
        return str_starts_with($this->mimeType, 'image/');
    }

    public function tamanhoFormatado(): string
    {
        if ($this->tamanho < 1024) {
            return $this->tamanho . ' B';
        }

        if ($this->tamanho < 1048576) {
            return round($this->tamanho / 1024, 1) . ' KB';
        }

        return round($this->tamanho / 1048576, 1) . ' MB';
    }
}
