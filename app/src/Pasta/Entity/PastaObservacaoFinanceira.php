<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaObservacaoFinanceiraRepository;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PastaObservacaoFinanceiraRepository::class)]
#[ORM\Table(name: 'pasta_observacao_financeira')]
#[ORM\Index(name: 'idx_pasta_obs_fin_pasta_id', columns: ['pasta_id'])]
#[ORM\Index(name: 'idx_pasta_obs_fin_tenant', columns: ['tenant_id'])]
class PastaObservacaoFinanceira implements Auditavel, TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Pasta::class, inversedBy: 'observacoesFinanceiras')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Pasta $pasta = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $autor = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[Assert\NotBlank(message: 'O conteúdo é obrigatório.')]
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

    /**
     * Indica se a observação pertence ao usuário informado (comparação por
     * instância e, como reforço, por id — útil quando há proxies do ORM).
     */
    public function pertenceAo(User $user): bool
    {
        if ($this->autor === null) {
            return false;
        }

        return $this->autor === $user
            || ($this->autor->getId() !== null && $this->autor->getId() === $user->getId());
    }
}
