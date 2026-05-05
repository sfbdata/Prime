<?php

namespace App\Cliente\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity]
#[UniqueEntity('cpf', message: 'Este CPF já está cadastrado.')]
class ClientePF extends Cliente
{
    #[ORM\Column(length: 14, unique: true)]
    private string $cpf;

    #[ORM\Column(length: 20)]
    private string $rg;

    #[ORM\Column(length: 100)]
    private string $rgOrgaoExpedidor;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $rgDataEmissao = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dataNascimento = null;

    #[ORM\Column(length: 50)]
    private string $nomeCompleto;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $estadoCivil = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $profissao = null;

    public function getNomeExibicao(): string
    {
        return $this->nomeCompleto;
    }

    public function getCpf(): string
    {
        return $this->cpf;
    }

    public function setCpf(string $cpf): self
    {
        $this->cpf = $cpf;
        return $this;
    }

    public function getRg(): string
    {
        return $this->rg;
    }

    public function setRg(string $rg): self
    {
        $this->rg = mb_strtoupper(trim($rg));
        return $this;
    }

    public function getRgOrgaoExpedidor(): string
    {
        return $this->rgOrgaoExpedidor;
    }

    public function setRgOrgaoExpedidor(string $rgOrgaoExpedidor): self
    {
        $this->rgOrgaoExpedidor = mb_strtoupper(trim($rgOrgaoExpedidor));
        return $this;
    }

    public function getRgDataEmissao(): ?\DateTimeImmutable
    {
        return $this->rgDataEmissao;
    }

    public function setRgDataEmissao(?\DateTimeImmutable $rgDataEmissao): self
    {
        $this->rgDataEmissao = $rgDataEmissao;
        return $this;
    }

    public function getDataNascimento(): ?\DateTimeImmutable
    {
        return $this->dataNascimento;
    }

    public function setDataNascimento(?\DateTimeImmutable $dataNascimento): self
    {
        $this->dataNascimento = $dataNascimento;
        return $this;
    }

    public function getNomeCompleto(): string
    {
        return $this->nomeCompleto;
    }

    public function setNomeCompleto(string $nomeCompleto): self
    {
        $this->nomeCompleto = mb_strtoupper(trim($nomeCompleto));
        return $this;
    }

    public function getEstadoCivil(): ?string
    {
        return $this->estadoCivil;
    }

    public function setEstadoCivil(?string $estadoCivil): self
    {
        $this->estadoCivil = $estadoCivil !== null ? mb_strtoupper(trim($estadoCivil)) : null;
        return $this;
    }

    public function getProfissao(): ?string
    {
        return $this->profissao;
    }

    public function setProfissao(?string $profissao): self
    {
        $this->profissao = $profissao !== null ? mb_strtoupper(trim($profissao)) : null;
        return $this;
    }
}
