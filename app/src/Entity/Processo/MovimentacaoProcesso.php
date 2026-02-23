<?php

namespace App\Entity\Processo;

use App\Repository\MovimentacaoProcessoRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MovimentacaoProcessoRepository::class)]
class MovimentacaoProcesso
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $dataMovimentacao = null;

    #[ORM\Column(length: 255)]
    private string $descricao;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $tipo = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $orgao = null;

    #[ORM\ManyToOne(targetEntity: Processo::class, inversedBy: 'movimentacoes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Processo $processo = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDataMovimentacao(): ?\DateTimeInterface
    {
        return $this->dataMovimentacao;
    }

    public function setDataMovimentacao(?\DateTimeInterface $dataMovimentacao): self
    {
        $this->dataMovimentacao = $dataMovimentacao;
        return $this;
    }

    public function getDescricao(): string
    {
        return $this->descricao;
    }

    public function setDescricao(string $descricao): self
    {
        $this->descricao = $descricao;
        return $this;
    }

    public function getTipo(): ?string
    {
        return $this->tipo;
    }

    public function setTipo(?string $tipo): self
    {
        $this->tipo = $tipo;
        return $this;
    }

    public function getOrgao(): ?string
    {
        return $this->orgao;
    }

    public function setOrgao(?string $orgao): self
    {
        $this->orgao = $orgao;
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
