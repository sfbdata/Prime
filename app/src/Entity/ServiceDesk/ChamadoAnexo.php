<?php

namespace App\Entity\ServiceDesk;

use App\Entity\Auth\User;
use App\Repository\ChamadoAnexoRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ChamadoAnexoRepository::class)]
class ChamadoAnexo
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Chamado::class, inversedBy: 'anexos')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Chamado $chamado = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $enviadoPor = null;

    #[ORM\Column(length: 255)]
    private string $nomeOriginal = '';

    #[ORM\Column(length: 255)]
    private string $nomeArquivo = '';

    #[ORM\Column(length: 100)]
    private string $mimeType = '';

    #[ORM\Column(type: 'integer')]
    private int $tamanho = 0;

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

    public function getEnviadoPor(): ?User
    {
        return $this->enviadoPor;
    }

    public function setEnviadoPor(User $enviadoPor): self
    {
        $this->enviadoPor = $enviadoPor;
        return $this;
    }

    public function getNomeOriginal(): string
    {
        return $this->nomeOriginal;
    }

    public function setNomeOriginal(string $nomeOriginal): self
    {
        $this->nomeOriginal = $nomeOriginal;
        return $this;
    }

    public function getNomeArquivo(): string
    {
        return $this->nomeArquivo;
    }

    public function setNomeArquivo(string $nomeArquivo): self
    {
        $this->nomeArquivo = $nomeArquivo;
        return $this;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function setMimeType(string $mimeType): self
    {
        $this->mimeType = $mimeType;
        return $this;
    }

    public function getTamanho(): int
    {
        return $this->tamanho;
    }

    public function setTamanho(int $tamanho): self
    {
        $this->tamanho = $tamanho;
        return $this;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    // Métodos auxiliares

    public function getTamanhoFormatado(): string
    {
        $bytes = $this->tamanho;
        $unidades = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($unidades) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $unidades[$i];
    }

    public function getIcone(): string
    {
        if (str_starts_with($this->mimeType, 'image/')) {
            return 'bi-file-image';
        }
        if (str_starts_with($this->mimeType, 'application/pdf')) {
            return 'bi-file-pdf';
        }
        if (str_contains($this->mimeType, 'word') || str_contains($this->mimeType, 'document')) {
            return 'bi-file-word';
        }
        if (str_contains($this->mimeType, 'excel') || str_contains($this->mimeType, 'spreadsheet')) {
            return 'bi-file-excel';
        }
        if (str_contains($this->mimeType, 'zip') || str_contains($this->mimeType, 'compressed')) {
            return 'bi-file-zip';
        }
        if (str_starts_with($this->mimeType, 'text/')) {
            return 'bi-file-text';
        }
        return 'bi-file-earmark';
    }

    public function getCaminho(): string
    {
        return 'uploads/chamados/' . $this->nomeArquivo;
    }
}
