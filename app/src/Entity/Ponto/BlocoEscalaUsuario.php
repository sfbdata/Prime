<?php

namespace App\Entity\Ponto;

use App\Repository\Ponto\BlocoEscalaUsuarioRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BlocoEscalaUsuarioRepository::class)]
class BlocoEscalaUsuario
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'blocos')]
    #[ORM\JoinColumn(nullable: false)]
    private ?EscalaTrabalho $escalaTrabalho = null;

    #[ORM\Column(type: 'json')]
    private array $diasSemana = []; // [1-7], 1=Segunda ... 7=Domingo

    #[ORM\Column(length: 5)]
    private string $entrada = '09:00'; // HH:mm

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $repouso = null; // HH:mm

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $retorno = null; // HH:mm

    #[ORM\Column(length: 5)]
    private string $saida = '18:00'; // HH:mm

    #[ORM\Column]
    private int $minutosBloco = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEscalaTrabalho(): ?EscalaTrabalho
    {
        return $this->escalaTrabalho;
    }

    public function setEscalaTrabalho(?EscalaTrabalho $escalaTrabalho): static
    {
        $this->escalaTrabalho = $escalaTrabalho;
        return $this;
    }

    public function getDiasSemana(): array
    {
        return $this->diasSemana;
    }

    public function setDiasSemana(array $diasSemana): static
    {
        $this->diasSemana = $diasSemana;
        return $this;
    }

    public function getEntrada(): string
    {
        return $this->entrada;
    }

    public function setEntrada(string $entrada): static
    {
        $this->entrada = $entrada;
        return $this;
    }

    public function getRepouso(): ?string
    {
        return $this->repouso;
    }

    public function setRepouso(?string $repouso): static
    {
        $this->repouso = $repouso;
        return $this;
    }

    public function getRetorno(): ?string
    {
        return $this->retorno;
    }

    public function setRetorno(?string $retorno): static
    {
        $this->retorno = $retorno;
        return $this;
    }

    public function getSaida(): string
    {
        return $this->saida;
    }

    public function setSaida(string $saida): static
    {
        $this->saida = $saida;
        return $this;
    }

    public function getMinutosBloco(): int
    {
        return $this->minutosBloco;
    }

    public function setMinutosBloco(int $minutosBloco): static
    {
        $this->minutosBloco = $minutosBloco;
        return $this;
    }
}
