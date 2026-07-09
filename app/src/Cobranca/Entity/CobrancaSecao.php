<?php

declare(strict_types=1);

namespace App\Cobranca\Entity;

use App\Cobranca\Repository\CobrancaSecaoRepository;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Seção (pasta lógica) de documentos de um Caso de Cobrança (Etapa 6). Organização opcional dos
 * documentos do caso — análoga à PastaSecao, mas ancorada no CasoCobranca (não na Pasta), porque o
 * Caso possui documentos mesmo SEM Pasta (invariável 25). Vínculo com o Caso é unidirecional
 * (navegação pelo CobrancaSecaoRepository); ON DELETE CASCADE do banco derruba a seção se o caso for
 * apagado. Não-final: proxies do Doctrine.
 */
#[ORM\Entity(repositoryClass: CobrancaSecaoRepository::class)]
#[ORM\Table(name: 'cobranca_secao')]
#[ORM\Index(name: 'idx_cobranca_secao_caso', columns: ['caso_id'])]
#[ORM\Index(name: 'idx_cobranca_secao_tenant', columns: ['tenant_id'])]
class CobrancaSecao implements TenantAware, Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CasoCobranca::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CasoCobranca $caso = null;

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

    /** @var Collection<int, CobrancaDocumento> */
    #[ORM\OneToMany(mappedBy: 'secao', targetEntity: CobrancaDocumento::class, cascade: ['remove'])]
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

    public function getCaso(): ?CasoCobranca
    {
        return $this->caso;
    }

    public function setCaso(CasoCobranca $caso): self
    {
        $this->caso = $caso;

        return $this;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(Tenant $tenant): self
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

    /** @return Collection<int, CobrancaDocumento> */
    public function getDocumentos(): Collection
    {
        return $this->documentos;
    }
}
