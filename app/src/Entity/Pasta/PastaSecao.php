<?php

declare(strict_types=1);

namespace App\Entity\Pasta;

use App\Entity\Tenant\Tenant;
use App\Repository\Pasta\PastaSecaoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PastaSecaoRepository::class)]
#[ORM\Table(name: 'pasta_secao')]
#[ORM\Index(name: 'idx_pasta_secao_pasta', columns: ['pasta_id'])]
#[ORM\Index(name: 'idx_pasta_secao_tenant', columns: ['tenant_id'])]
class PastaSecao
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Pasta::class, inversedBy: 'secoes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Pasta $pasta = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 255)]
    private string $nome = '';

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $ordem = 0;

    #[ORM\Column(name: 'criada_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadaEm;

    #[ORM\OneToMany(mappedBy: 'secao', targetEntity: PastaDocumento::class, cascade: ['remove'])]
    private Collection $documentos;

    public function __construct()
    {
        $this->criadaEm = new \DateTimeImmutable();
        $this->documentos = new ArrayCollection();
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

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(?Tenant $tenant): self
    {
        $this->tenant = $tenant;

        return $this;
    }

    public function getNome(): string
    {
        return $this->nome;
    }

    public function setNome(string $nome): self
    {
        $this->nome = mb_strtoupper(trim($nome));

        return $this;
    }

    public function getOrdem(): int
    {
        return $this->ordem;
    }

    public function setOrdem(int $ordem): self
    {
        $this->ordem = $ordem;

        return $this;
    }

    public function getCriadaEm(): \DateTimeImmutable
    {
        return $this->criadaEm;
    }

    /** @return Collection<int, PastaDocumento> */
    public function getDocumentos(): Collection
    {
        return $this->documentos;
    }
}
