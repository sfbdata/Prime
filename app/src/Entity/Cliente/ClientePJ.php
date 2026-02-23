<?php

namespace App\Entity\Cliente;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity]
#[UniqueEntity('cnpj', message: 'Este CNPJ já está cadastrado.')]
class ClientePJ extends Cliente
{
    #[ORM\Column(length: 14, unique: true)]
    private string $cnpj;

    #[ORM\Column(length: 255)]
    private string $razaoSocial;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nomeFantasia = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $inscricaoEstadual = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $inscricaoMunicipal = null;

    #[ORM\Column(length: 255)]
    private string $enderecSede;

    #[ORM\Column(length: 255)]
    private string $representanteLegal;

    #[ORM\Column(length: 14)]
    private string $representanteCpf;

    #[ORM\Column(length: 20)]
    private string $representanteRg;

    #[ORM\Column(length: 100)]
    private string $representanteCargo;

    public function getCnpj(): string
    {
        return $this->cnpj;
    }

    public function setCnpj(string $cnpj): self
    {
        $this->cnpj = $cnpj;
        return $this;
    }

    public function getRazaoSocial(): string
    {
        return $this->razaoSocial;
    }

    public function setRazaoSocial(string $razaoSocial): self
    {
        $this->razaoSocial = $razaoSocial;
        return $this;
    }

    public function getNomeFantasia(): ?string
    {
        return $this->nomeFantasia;
    }

    public function setNomeFantasia(?string $nomeFantasia): self
    {
        $this->nomeFantasia = $nomeFantasia;
        return $this;
    }

    public function getInscricaoEstadual(): ?string
    {
        return $this->inscricaoEstadual;
    }

    public function setInscricaoEstadual(?string $inscricaoEstadual): self
    {
        $this->inscricaoEstadual = $inscricaoEstadual;
        return $this;
    }

    public function getInscricaoMunicipal(): ?string
    {
        return $this->inscricaoMunicipal;
    }

    public function setInscricaoMunicipal(?string $inscricaoMunicipal): self
    {
        $this->inscricaoMunicipal = $inscricaoMunicipal;
        return $this;
    }

    public function getEnderecSede(): string
    {
        return $this->enderecSede;
    }

    public function setEnderecSede(string $enderecSede): self
    {
        $this->enderecSede = $enderecSede;
        return $this;
    }

    public function getRepresentanteLegal(): string
    {
        return $this->representanteLegal;
    }

    public function setRepresentanteLegal(string $representanteLegal): self
    {
        $this->representanteLegal = $representanteLegal;
        return $this;
    }

    public function getRepresentanteCpf(): string
    {
        return $this->representanteCpf;
    }

    public function setRepresentanteCpf(string $representanteCpf): self
    {
        $this->representanteCpf = $representanteCpf;
        return $this;
    }

    public function getRepresentanteRg(): string
    {
        return $this->representanteRg;
    }

    public function setRepresentanteRg(string $representanteRg): self
    {
        $this->representanteRg = $representanteRg;
        return $this;
    }

    public function getRepresentanteCargo(): string
    {
        return $this->representanteCargo;
    }

    public function setRepresentanteCargo(string $representanteCargo): self
    {
        $this->representanteCargo = $representanteCargo;
        return $this;
    }
}
