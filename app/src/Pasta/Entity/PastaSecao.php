<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaSecaoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PastaSecaoRepository::class)]
#[ORM\Table(name: 'pasta_secao')]
#[ORM\Index(name: 'idx_pasta_secao_pasta', columns: ['pasta_id'])]
#[ORM\Index(name: 'idx_pasta_secao_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_pasta_secao_pai', columns: ['secao_pai_id'])]
class PastaSecao implements Auditavel, TenantAware
{
    /** Trava anti-laço na travessia da árvore (não é o teto de produto, que é 10). */
    private const LIMITE_SEGURANCA = 100;

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

    #[Assert\NotBlank(message: 'O nome é obrigatório.')]
    #[Assert\Length(max: 255, maxMessage: 'O nome não pode ter mais de {{ limit }} caracteres.')]
    #[ORM\Column(length: 255)]
    private string $nome = '';

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $ordem = 0;

    #[ORM\Column(name: 'criada_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadaEm;

    #[ORM\OneToMany(mappedBy: 'secao', targetEntity: PastaDocumento::class, cascade: ['remove'])]
    private Collection $documentos;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'filhas')]
    #[ORM\JoinColumn(name: 'secao_pai_id', nullable: true, onDelete: 'CASCADE')]
    private ?self $pai = null;

    /** @var Collection<int, PastaSecao> */
    #[ORM\OneToMany(mappedBy: 'pai', targetEntity: self::class, cascade: ['remove'])]
    private Collection $filhas;

    public function __construct()
    {
        $this->criadaEm = new \DateTimeImmutable();
        $this->documentos = new ArrayCollection();
        $this->filhas = new ArrayCollection();
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

    public function getPai(): ?self
    {
        return $this->pai;
    }

    public function setPai(?self $pai): self
    {
        $this->pai = $pai;

        return $this;
    }

    /** @return Collection<int, PastaSecao> */
    public function getFilhas(): Collection
    {
        return $this->filhas;
    }

    /**
     * Nível desta seção na árvore. Seção sem pai está no nível 1.
     *
     * O teto de LIMITE_SEGURANCA não é o teto de produto (que é 10, validado nos UseCases) — é
     * uma trava contra ciclo gravado no banco, que aqui viraria laço infinito.
     */
    public function getProfundidade(): int
    {
        $nivel = 1;
        $atual = $this->pai;
        while ($atual !== null && $nivel < self::LIMITE_SEGURANCA) {
            ++$nivel;
            $atual = $atual->getPai();
        }

        return $nivel;
    }

    /** Quantos níveis a subárvore desta seção ocupa, contando ela mesma. Folha = 1. */
    public function getAltura(): int
    {
        $altura = 1;
        foreach ($this->filhas as $filha) {
            $altura = max($altura, 1 + $filha->getAltura());
        }

        return $altura;
    }

    /** Esta seção está em algum lugar abaixo de $possivelAncestral? Não considera a si mesma. */
    public function descendeDe(self $possivelAncestral): bool
    {
        $passos = 0;
        $atual  = $this->pai;
        while ($atual !== null && $passos < self::LIMITE_SEGURANCA) {
            if ($atual === $possivelAncestral) {
                return true;
            }
            $atual = $atual->getPai();
            ++$passos;
        }

        return false;
    }
}
