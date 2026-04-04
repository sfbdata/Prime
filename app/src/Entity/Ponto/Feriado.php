<?php

namespace App\Entity\Ponto;

use App\Entity\Tenant\Tenant;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\Ponto\FeriadoRepository::class)]
class Feriado
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $nome = null;

    #[ORM\Column(type: 'date')]
    private ?\DateTimeInterface $data = null;

    #[ORM\Column]
    private ?bool $recorrente = true; // Se repete todo ano

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNome(): ?string
    {
        return $this->nome;
    }

    public function setNome(string $nome): static
    {
        $this->nome = $nome;
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

    public function isRecorrente(): ?bool
    {
        return $this->recorrente;
    }

    public function setRecorrente(bool $recorrente): static
    {
        $this->recorrente = $recorrente;
        return $this;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(?Tenant $tenant): static
    {
        $this->tenant = $tenant;
        return $this;
    }
}
