<?php

namespace App\Entity;

use App\Repository\ParteProcessoRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ParteProcessoRepository::class)]
class ParteProcesso
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 30)]
    private string $tipo;

    #[ORM\Column(length: 255)]
    private string $nome;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $documento = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $papel = null;

    #[ORM\ManyToOne(targetEntity: Processo::class, inversedBy: 'partes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Processo $processo = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getNome(): string
    {
        return $this->nome;
    }

    public function setNome(string $nome): self
    {
        $this->nome = $nome;
        return $this;
    }

    public function getDocumento(): ?string
    {
        return $this->documento;
    }

    public function setDocumento(?string $documento): self
    {
        $this->documento = $documento;
        return $this;
    }

    public function getPapel(): ?string
    {
        return $this->papel;
    }

    public function setPapel(?string $papel): self
    {
        $this->papel = $papel;
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
}
