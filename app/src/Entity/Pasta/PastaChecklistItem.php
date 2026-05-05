<?php

declare(strict_types=1);

namespace App\Entity\Pasta;

use App\Entity\Tenant\Tenant;
use App\Repository\Pasta\PastaChecklistItemRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PastaChecklistItemRepository::class)]
#[ORM\Table(name: 'pasta_checklist_item')]
#[ORM\Index(name: 'idx_pasta_checklist_item_pasta', columns: ['pasta_id'])]
#[ORM\Index(name: 'idx_pasta_checklist_item_tenant', columns: ['tenant_id'])]
class PastaChecklistItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Pasta::class, inversedBy: 'checklistItens')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Pasta $pasta = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 255)]
    private string $titulo = '';

    #[ORM\Column(options: ['default' => false])]
    private bool $concluido = false;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $ordem = 0;

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

    public function getTitulo(): string
    {
        return $this->titulo;
    }

    public function setTitulo(string $titulo): self
    {
        $this->titulo = mb_strtoupper(trim($titulo));

        return $this;
    }

    public function isConcluido(): bool
    {
        return $this->concluido;
    }

    public function setConcluido(bool $concluido): self
    {
        $this->concluido = $concluido;

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

    public function toggle(): void
    {
        $this->concluido = !$this->concluido;
    }
}
