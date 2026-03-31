<?php

namespace App\Entity\Ponto;

use App\Entity\Auth\User;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class JustificativaPonto
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(type: 'date')]
    private ?\DateTimeInterface $data = null;

    #[ORM\Column(type: 'text')]
    private ?string $descricao = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $anexoPath = null; // Caminho para o arquivo (PDF/Imagem)

    #[ORM\Column(length: 20)]
    private ?string $status = 'pendente'; // pendente, aprovado, rejeitado

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $dataAnalise = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $analisadoPor = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $observacaoAnalise = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getData(): ?\DateTimeInterface
    {
        return $this->data;
    }

    public function setData(\DateTimeInterface $data): static
    {
        $this->data = $data;
        return $this;
    }

    public function getDescricao(): ?string
    {
        return $this->descricao;
    }

    public function setDescricao(string $descricao): static
    {
        $this->descricao = $descricao;
        return $this;
    }

    public function getAnexoPath(): ?string
    {
        return $this->anexoPath;
    }

    public function setAnexoPath(?string $anexoPath): static
    {
        $this->anexoPath = $anexoPath;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getDataAnalise(): ?\DateTimeInterface
    {
        return $this->dataAnalise;
    }

    public function setDataAnalise(?\DateTimeInterface $dataAnalise): static
    {
        $this->dataAnalise = $dataAnalise;
        return $this;
    }

    public function getAnalisadoPor(): ?User
    {
        return $this->analisadoPor;
    }

    public function setAnalisadoPor(?User $analisadoPor): static
    {
        $this->analisadoPor = $analisadoPor;
        return $this;
    }

    public function getObservacaoAnalise(): ?string
    {
        return $this->observacaoAnalise;
    }

    public function setObservacaoAnalise(?string $observacaoAnalise): static
    {
        $this->observacaoAnalise = $observacaoAnalise;
        return $this;
    }
}
