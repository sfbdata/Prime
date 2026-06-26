<?php

namespace App\Cliente\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity]
#[UniqueEntity(fields: ['cnpj', 'tenant'], message: 'Este CNPJ já está cadastrado.')]
class ClientePJ extends Cliente
{
    #[ORM\Column(length: 14)]
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

    public function getNomeExibicao(): string
    {
        return $this->razaoSocial;
    }

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
        $this->razaoSocial = mb_strtoupper(trim($razaoSocial));
        return $this;
    }

    public function getNomeFantasia(): ?string
    {
        return $this->nomeFantasia;
    }

    public function setNomeFantasia(?string $nomeFantasia): self
    {
        $this->nomeFantasia = $nomeFantasia !== null ? mb_strtoupper(trim($nomeFantasia)) : null;
        return $this;
    }

    public function getInscricaoEstadual(): ?string
    {
        return $this->inscricaoEstadual;
    }

    public function setInscricaoEstadual(?string $inscricaoEstadual): self
    {
        $this->inscricaoEstadual = $inscricaoEstadual !== null ? mb_strtoupper(trim($inscricaoEstadual)) : null;
        return $this;
    }

    public function getInscricaoMunicipal(): ?string
    {
        return $this->inscricaoMunicipal;
    }

    public function setInscricaoMunicipal(?string $inscricaoMunicipal): self
    {
        $this->inscricaoMunicipal = $inscricaoMunicipal !== null ? mb_strtoupper(trim($inscricaoMunicipal)) : null;
        return $this;
    }

    public function getEnderecSede(): string
    {
        return $this->enderecSede;
    }

    public function setEnderecSede(string $enderecSede): self
    {
        $this->enderecSede = mb_strtoupper(trim($enderecSede));
        return $this;
    }

    public function getRepresentanteLegal(): string
    {
        return $this->representanteLegal;
    }

    public function setRepresentanteLegal(string $representanteLegal): self
    {
        $this->representanteLegal = mb_strtoupper(trim($representanteLegal));
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
        $this->representanteRg = mb_strtoupper(trim($representanteRg));
        return $this;
    }

    public function getRepresentanteCargo(): string
    {
        return $this->representanteCargo;
    }

    public function setRepresentanteCargo(string $representanteCargo): self
    {
        $this->representanteCargo = mb_strtoupper(trim($representanteCargo));
        return $this;
    }
}
