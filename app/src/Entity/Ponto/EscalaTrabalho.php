<?php

namespace App\Entity\Ponto;

use App\Entity\Auth\User;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\Ponto\EscalaTrabalhoRepository::class)]
class EscalaTrabalho
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'escalaTrabalho')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    // Horários em formato HH:mm
    #[ORM\Column(length: 5, nullable: true)]
    private ?string $entrada1 = '09:00';

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $saida1 = '12:00';

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $entrada2 = '13:00';

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $saida2 = '18:00';

    #[ORM\Column]
    private ?int $cargaHorariaDiaria = 480; // em minutos (8h)

    #[ORM\Column]
    private array $diasSemana = [1, 2, 3, 4, 5]; // 1=Segunda, ..., 7=Domingo

    // Sábado: jornada corrida (sem intervalo de almoço)
    #[ORM\Column(length: 5, nullable: true)]
    private ?string $entradaSabado = null;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $saidaSabado = null;

    #[ORM\Column(nullable: true)]
    private ?int $cargaHorariaSabado = null; // em minutos

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getEntrada1(): ?string
    {
        return $this->entrada1;
    }

    public function setEntrada1(?string $entrada1): static
    {
        $this->entrada1 = $entrada1;
        return $this;
    }

    public function getSaida1(): ?string
    {
        return $this->saida1;
    }

    public function setSaida1(?string $saida1): static
    {
        $this->saida1 = $saida1;
        return $this;
    }

    public function getEntrada2(): ?string
    {
        return $this->entrada2;
    }

    public function setEntrada2(?string $entrada2): static
    {
        $this->entrada2 = $entrada2;
        return $this;
    }

    public function getSaida2(): ?string
    {
        return $this->saida2;
    }

    public function setSaida2(?string $saida2): static
    {
        $this->saida2 = $saida2;
        return $this;
    }

    public function getCargaHorariaDiaria(): ?int
    {
        return $this->cargaHorariaDiaria;
    }

    public function setCargaHorariaDiaria(int $cargaHorariaDiaria): static
    {
        $this->cargaHorariaDiaria = $cargaHorariaDiaria;
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

    public function getEntradaSabado(): ?string
    {
        return $this->entradaSabado;
    }

    public function setEntradaSabado(?string $entradaSabado): static
    {
        $this->entradaSabado = $entradaSabado;
        return $this;
    }

    public function getSaidaSabado(): ?string
    {
        return $this->saidaSabado;
    }

    public function setSaidaSabado(?string $saidaSabado): static
    {
        $this->saidaSabado = $saidaSabado;
        return $this;
    }

    public function getCargaHorariaSabado(): ?int
    {
        return $this->cargaHorariaSabado;
    }

    public function setCargaHorariaSabado(?int $cargaHorariaSabado): static
    {
        $this->cargaHorariaSabado = $cargaHorariaSabado;
        return $this;
    }
}
