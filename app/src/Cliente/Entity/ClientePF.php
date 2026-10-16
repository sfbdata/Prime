<?php

namespace App\Cliente\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity]
#[UniqueEntity(fields: ['cpf', 'tenant'], message: 'Este CPF já está cadastrado.')]
class ClientePF extends Cliente
{
    #[ORM\Column(length: 14)]
    private string $cpf;

    #[ORM\Column(length: 20)]
    private string $rg;

    #[ORM\Column(length: 100)]
    private string $rgOrgaoExpedidor;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $rgDataEmissao = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dataNascimento = null;

    #[ORM\Column(length: 150)]
    private string $nomeCompleto;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $estadoCivil = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $profissao = null;

    /** "brasileiro(a)" por padrão na tela (desenho 1.2.3); guardado como escrito. */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $nacionalidade = null;

    /** MASCULINO | FEMININO | null: decide a concordância da qualificação ("nascido", "nascida", "nascido(a)"). */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $genero = null;

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

    public const GENEROS = ['MASCULINO', 'FEMININO'];

    public function getNacionalidade(): ?string
    {
        return $this->nacionalidade;
    }

    public function setNacionalidade(?string $nacionalidade): self
    {
        $nacionalidade = $nacionalidade !== null ? mb_strtolower(trim($nacionalidade)) : null;
        $this->nacionalidade = $nacionalidade !== '' ? $nacionalidade : null;

        return $this;
    }

    public function getGenero(): ?string
    {
        return $this->genero;
    }

    public function setGenero(?string $genero): self
    {
        $genero = $genero !== null ? mb_strtoupper(trim($genero)) : null;
        $this->genero = in_array($genero, self::GENEROS, true) ? $genero : null;

        return $this;
    }
}
