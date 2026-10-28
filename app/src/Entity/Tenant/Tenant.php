<?php

namespace App\Entity\Tenant;

use App\Entity\Auth\User;
use App\Ponto\Entity\JornadaTenant;
use App\Entity\Tenant\Cargo;
use App\Entity\Tenant\Lotacao;
use App\Entity\Tenant\Sede;
use App\Repository\TenantRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Shared\Contract\Auditavel;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TenantRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_tenant_codigo_conta', columns: ['codigo_conta'], options: ['where' => '(codigo_conta IS NOT NULL)'])]
class Tenant implements Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 14, nullable: true)]
    private ?string $cnpj = null;

    #[ORM\Column(length: 9, nullable: true)]
    private ?string $cep = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logradouro = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $numero = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $complemento = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $bairro = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $cidade = null;

    #[ORM\Column(length: 2, nullable: true)]
    private ?string $estado = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?bool $isActive = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $excluidoEm = null;

    /**
     * @var Collection<int, TenantRole>
     */
    #[ORM\OneToMany(targetEntity: TenantRole::class, mappedBy: 'tenant', cascade: ['persist', 'remove'])]
    private Collection $roles;

    /**
     * @var Collection<int, Sede>
     */
    #[ORM\OneToMany(targetEntity: Sede::class, mappedBy: 'tenant', cascade: ['persist', 'remove'])]
    private Collection $sedes;

    /**
     * @var Collection<int, Cargo>
     */
    #[ORM\OneToMany(targetEntity: Cargo::class, mappedBy: 'tenant', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $cargos;

    /**
     * @var Collection<int, Lotacao>
     */
    #[ORM\OneToMany(targetEntity: Lotacao::class, mappedBy: 'tenant', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $lotacoes;

    #[ORM\OneToOne(mappedBy: 'tenant', targetEntity: JornadaTenant::class, cascade: ['persist', 'remove'], orphanRemoval: false)]
    private ?JornadaTenant $jornadaTenant = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $criadoPor = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $responsavelAssinatura = null;

    /** Registro na OAB do escritório, como o desenho (texto livre, ex.: "OAB/SP 123.456"). */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $oab = null;

    #[ORM\Column(name: 'email_contato', length: 180, nullable: true)]
    private ?string $emailContato = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telefone = null;

    /** Só a chave da logo no armazenamento (nunca caminho). Fora da trilha de auditoria. */
    #[ORM\Column(name: 'logo_chave', length: 255, nullable: true)]
    private ?string $logoChave = null;

    #[ORM\Column(name: 'alterado_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $alteradoEm = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'alterado_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $alteradoPor = null;

    /** "BJ-XXXX-XXXX-XXXX": o código da conta que o suporte e o cliente citam; gerado sob demanda quando nulo. */
    #[ORM\Column(name: 'codigo_conta', length: 20, nullable: true)]
    private ?string $codigoConta = null;

    public function __construct()
    {
        $this->roles = new ArrayCollection();
        $this->sedes = new ArrayCollection();
        $this->cargos = new ArrayCollection();
        $this->lotacoes = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable(); // sempre preenchido automaticamente
        $this->isActive = true; // sempre ativo ao criar
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getCnpj(): ?string
    {
        return $this->cnpj;
    }

    public function setCnpj(?string $cnpj): static
    {
        $this->cnpj = $cnpj;
        return $this;
    }

    public function getCep(): ?string
    {
        return $this->cep;
    }

    public function setCep(?string $cep): static
    {
        $this->cep = $cep;
        return $this;
    }

    public function getLogradouro(): ?string
    {
        return $this->logradouro;
    }

    public function setLogradouro(?string $logradouro): static
    {
        $this->logradouro = $logradouro;
        return $this;
    }

    public function getNumero(): ?string
    {
        return $this->numero;
    }

    public function setNumero(?string $numero): static
    {
        $this->numero = $numero;
        return $this;
    }

    public function getComplemento(): ?string
    {
        return $this->complemento;
    }

    public function setComplemento(?string $complemento): static
    {
        $this->complemento = $complemento;
        return $this;
    }

    public function getBairro(): ?string
    {
        return $this->bairro;
    }

    public function setBairro(?string $bairro): static
    {
        $this->bairro = $bairro;
        return $this;
    }

    public function getCidade(): ?string
    {
        return $this->cidade;
    }

    public function setCidade(?string $cidade): static
    {
        $this->cidade = $cidade;
        return $this;
    }

    public function getEstado(): ?string
    {
        return $this->estado;
    }

    public function setEstado(?string $estado): static
    {
        $this->estado = $estado;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isActive(): ?bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getExcluidoEm(): ?\DateTimeImmutable
    {
        return $this->excluidoEm;
    }

    public function setExcluidoEm(?\DateTimeImmutable $excluidoEm): static
    {
        $this->excluidoEm = $excluidoEm;
        return $this;
    }

    /**
     * @return Collection<int, TenantRole>
     */
    public function getRoles(): Collection
    {
        return $this->roles;
    }

    /**
     * @return Collection<int, Sede>
     */
    public function getSedes(): Collection
    {
        return $this->sedes;
    }

    public function addSede(Sede $sede): static
    {
        if (!$this->sedes->contains($sede)) {
            $this->sedes->add($sede);
            $sede->setTenant($this);
        }
        return $this;
    }

    public function removeSede(Sede $sede): static
    {
        if ($this->sedes->removeElement($sede)) {
            if ($sede->getTenant() === $this) {
                $sede->setTenant(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, Cargo>
     */
    public function getCargos(): Collection
    {
        return $this->cargos;
    }

    public function addCargo(Cargo $cargo): static
    {
        if (!$this->cargos->contains($cargo)) {
            $this->cargos->add($cargo);
            $cargo->setTenant($this);
        }
        return $this;
    }

    public function removeCargo(Cargo $cargo): static
    {
        if ($this->cargos->removeElement($cargo)) {
            if ($cargo->getTenant() === $this) {
                $cargo->setTenant(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, Lotacao>
     */
    public function getLotacoes(): Collection
    {
        return $this->lotacoes;
    }

    public function addLotacao(Lotacao $lotacao): static
    {
        if (!$this->lotacoes->contains($lotacao)) {
            $this->lotacoes->add($lotacao);
            $lotacao->setTenant($this);
        }
        return $this;
    }

    public function removeLotacao(Lotacao $lotacao): static
    {
        if ($this->lotacoes->removeElement($lotacao)) {
            if ($lotacao->getTenant() === $this) {
                $lotacao->setTenant(null);
            }
        }
        return $this;
    }

    public function getJornadaTenant(): ?JornadaTenant
    {
        return $this->jornadaTenant;
    }

    public function setJornadaTenant(?JornadaTenant $jornadaTenant): static
    {
        $this->jornadaTenant = $jornadaTenant;
        if ($jornadaTenant !== null && $jornadaTenant->getTenant() !== $this) {
            $jornadaTenant->setTenant($this);
        }
        return $this;
    }

    public function getCriadoPor(): ?User
    {
        return $this->criadoPor;
    }

    public function setCriadoPor(?User $criadoPor): static
    {
        $this->criadoPor = $criadoPor;
        return $this;
    }

    public function getResponsavelAssinatura(): ?string
    {
        return $this->responsavelAssinatura;
    }

    public function setResponsavelAssinatura(?string $responsavelAssinatura): static
    {
        $this->responsavelAssinatura = $responsavelAssinatura;
        return $this;
    }

    public function getOab(): ?string
    {
        return $this->oab;
    }

    public function setOab(?string $oab): static
    {
        $this->oab = $oab;
        return $this;
    }

    public function getEmailContato(): ?string
    {
        return $this->emailContato;
    }

    public function setEmailContato(?string $emailContato): static
    {
        $this->emailContato = $emailContato;
        return $this;
    }

    public function getTelefone(): ?string
    {
        return $this->telefone;
    }

    public function setTelefone(?string $telefone): static
    {
        $this->telefone = $telefone;
        return $this;
    }

    public function getLogoChave(): ?string
    {
        return $this->logoChave;
    }

    public function setLogoChave(?string $logoChave): static
    {
        $this->logoChave = $logoChave;
        return $this;
    }

    public function getAlteradoEm(): ?\DateTimeImmutable
    {
        return $this->alteradoEm;
    }

    public function getAlteradoPor(): ?User
    {
        return $this->alteradoPor;
    }

    /** Carimba quem alterou os dados ou a logo e quando. */
    public function carimbarAlteracao(User $por, \DateTimeImmutable $quando): static
    {
        $this->alteradoPor = $por;
        $this->alteradoEm = $quando;
        return $this;
    }

    public function getCodigoConta(): ?string
    {
        return $this->codigoConta;
    }

    public function setCodigoConta(?string $codigoConta): static
    {
        $this->codigoConta = $codigoConta;
        return $this;
    }
}
