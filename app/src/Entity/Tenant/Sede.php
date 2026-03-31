<?php

namespace App\Entity\Tenant;

use App\Repository\SedeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SedeRepository::class)]
class Sede
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $nome = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 8)]
    private ?string $latitude = null;

    #[ORM\Column(type: 'decimal', precision: 11, scale: 8)]
    private ?string $longitude = null;

    #[ORM\Column]
    private ?int $raioPermitido = 50; // em metros

    #[ORM\Column(length: 50)]
    private ?string $timezone = 'America/Sao_Paulo';

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $ssidsAutorizados = null;

    #[ORM\ManyToOne(inversedBy: 'sedes')]
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

    public function getLatitude(): ?string
    {
        return $this->latitude;
    }

    public function setLatitude(string $latitude): static
    {
        $this->latitude = $latitude;
        return $this;
    }

    public function getLongitude(): ?string
    {
        return $this->longitude;
    }

    public function setLongitude(string $longitude): static
    {
        $this->longitude = $longitude;
        return $this;
    }

    public function getRaioPermitido(): ?int
    {
        return $this->raioPermitido;
    }

    public function setRaioPermitido(int $raioPermitido): static
    {
        $this->raioPermitido = $raioPermitido;
        return $this;
    }

    public function getTimezone(): ?string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): static
    {
        $this->timezone = $timezone;
        return $this;
    }

    public function getSsidsAutorizados(): ?array
    {
        return $this->ssidsAutorizados;
    }

    public function setSsidsAutorizados(?array $ssidsAutorizados): static
    {
        $this->ssidsAutorizados = $ssidsAutorizados;
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
