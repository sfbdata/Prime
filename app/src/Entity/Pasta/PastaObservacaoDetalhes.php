<?php

declare(strict_types=1);

namespace App\Entity\Pasta;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\Pasta\PastaObservacaoDetalhesRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PastaObservacaoDetalhesRepository::class)]
#[ORM\Table(name: 'pasta_observacao_detalhes')]
#[ORM\Index(name: 'idx_pasta_obs_det_pasta_id', columns: ['pasta_id'])]
#[ORM\Index(name: 'idx_pasta_obs_det_tenant', columns: ['tenant_id'])]
class PastaObservacaoDetalhes
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Pasta::class, inversedBy: 'observacoesDetalhes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Pasta $pasta = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $autor = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\Column(type: 'text')]
    private string $conteudo = '';

    #[ORM\Column(name: 'criada_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadaEm;

    #[ORM\Column(name: 'editada_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $editadaEm = null;

    public function __construct()
    {
        $this->criadaEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPasta(): ?Pasta
    {
        return $this->pasta;
    }

    public function setPasta(?Pasta $pasta): self
    {
        $this->pasta = $pasta;

        return $this;
    }

    public function getAutor(): ?User
    {
        return $this->autor;
    }

    public function setAutor(?User $autor): self
    {
        $this->autor = $autor;

        return $this;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(?Tenant $tenant): self
    {
        $this->tenant = $tenant;

        return $this;
    }

    public function getConteudo(): string
    {
        return $this->conteudo;
    }

    public function setConteudo(string $conteudo): self
    {
        $this->conteudo = $conteudo;

        return $this;
    }

    public function getCriadaEm(): \DateTimeImmutable
    {
        return $this->criadaEm;
    }

    public function getEditadaEm(): ?\DateTimeImmutable
    {
        return $this->editadaEm;
    }

    public function setEditadaEm(?\DateTimeImmutable $editadaEm): self
    {
        $this->editadaEm = $editadaEm;

        return $this;
    }
}
