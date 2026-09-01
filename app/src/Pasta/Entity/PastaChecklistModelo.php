<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaChecklistModeloRepository;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma lista de documentos salva com nome para ser reaproveitada em outras pastas.
 *
 * O modelo pende do ESCRITÓRIO, não de uma pasta: é isso que permite montá-lo numa
 * pasta e aplicá-lo em outra. A pasta de origem não fica gravada de propósito —
 * apagar a pasta onde o modelo nasceu não pode levar o modelo junto.
 *
 * O nome é único por escritório (e gravado em maiúsculas, como o resto do domínio),
 * para não existirem duas listas "TRABALHISTA" que ninguém sabe distinguir.
 */
#[ORM\Entity(repositoryClass: PastaChecklistModeloRepository::class)]
#[ORM\Table(name: 'pasta_checklist_modelo')]
#[ORM\Index(name: 'idx_pasta_checklist_modelo_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_pasta_checklist_modelo_tenant_nome', columns: ['tenant_id', 'nome'])]
class PastaChecklistModelo implements Auditavel, TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    /** Quem salvou. Some junto com o usuário; o modelo, não — ele é do escritório. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $autor = null;

    #[ORM\Column(length: 120)]
    private string $nome = '';

    #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    /** @var Collection<int, PastaChecklistModeloItem> */
    #[ORM\OneToMany(mappedBy: 'modelo', targetEntity: PastaChecklistModeloItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['ordem' => 'ASC'])]
    private Collection $itens;

    public function __construct()
    {
        $this->criadoEm = new \DateTimeImmutable();
        $this->itens    = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getAutor(): ?User
    {
        return $this->autor;
    }

    public function setAutor(?User $autor): self
    {
        $this->autor = $autor;

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

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    /** @return Collection<int, PastaChecklistModeloItem> */
    public function getItens(): Collection
    {
        return $this->itens;
    }

    public function adicionarItem(PastaChecklistModeloItem $item): self
    {
        if (!$this->itens->contains($item)) {
            $this->itens->add($item);
            $item->setModelo($this);
        }

        return $this;
    }

    /**
     * Troca a lista inteira. É o que "salvar por cima de um modelo que já existe" faz:
     * o orphanRemoval apaga os itens antigos no flush.
     */
    public function limparItens(): self
    {
        $this->itens->clear();

        return $this;
    }

    public function totalItens(): int
    {
        return $this->itens->count();
    }
}
