<?php

namespace App\Entity\Ponto;

use App\Entity\Auth\User;
use App\Entity\Tenant\Sede;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class RegistroPonto
{
    public const TIPO_ENTRADA = 'entrada';
    public const TIPO_SAIDA = 'saida';
    public const TIPOS_VALIDOS = [self::TIPO_ENTRADA, self::TIPO_SAIDA];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $dataHora = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 8)]
    private ?string $latitude = null;

    #[ORM\Column(type: 'decimal', precision: 11, scale: 8)]
    private ?string $longitude = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $precisaoGps = null; // em metros

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Sede $sede = null;

    #[ORM\Column(length: 20)]
    private ?string $tipo = self::TIPO_ENTRADA;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $observacao = null;

    public function __construct()
    {
        $this->dataHora = new \DateTime();
    }

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

    public function getDataHora(): ?\DateTimeInterface
    {
        return $this->dataHora;
    }

    public function setDataHora(\DateTimeInterface $dataHora): static
    {
        $this->dataHora = $dataHora;
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

    public function getPrecisaoGps(): ?string
    {
        return $this->precisaoGps;
    }

    public function setPrecisaoGps(string $precisaoGps): static
    {
        $this->precisaoGps = $precisaoGps;
        return $this;
    }

    public function getSede(): ?Sede
    {
        return $this->sede;
    }

    public function setSede(?Sede $sede): static
    {
        $this->sede = $sede;
        return $this;
    }

    public function getTipo(): ?string
    {
        return $this->tipo;
    }

    public function setTipo(string $tipo): static
    {
        if (!in_array($tipo, self::TIPOS_VALIDOS, true)) {
            throw new \InvalidArgumentException('Tipo de registro de ponto invalido.');
        }

        $this->tipo = $tipo;
        return $this;
    }

    public function getObservacao(): ?string
    {
        return $this->observacao;
    }

    public function setObservacao(?string $observacao): static
    {
        $this->observacao = $observacao;
        return $this;
    }
}
