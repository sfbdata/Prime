<?php

declare(strict_types=1);

namespace App\Cobranca\Entity;

use App\Cliente\Entity\Cliente;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\ModoCarteira;
use App\Cobranca\Enum\TipoVinculo;
use App\Cobranca\Repository\CarteiraRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Carteira de Cobrança (SPEC §4): operação/contexto de cobrança de um Cliente credor.
 * Concentra configurações e padrões de operação — modo A/B (SPEC §6), regra de honorários
 * padrão (SPEC §18), tolerância de atraso, tipo de vínculo preferido e rótulo do objeto na UI.
 * Toda carteira pertence a exatamente um cliente/credor (invariável 4).
 */
#[ORM\Entity(repositoryClass: CarteiraRepository::class)]
#[ORM\Table(name: 'cobranca_carteira')]
#[ORM\HasLifecycleCallbacks]
class Carteira implements TenantAware, Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    /** Cliente credor dono da carteira (invariável 4). */
    #[ORM\ManyToOne(targetEntity: Cliente::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Cliente $cliente = null;

    #[ORM\Column(length: 255)]
    private string $nome = '';

    /** Modo A (uma cobrança ativa por objeto) ou B (várias). SPEC §6. */
    #[ORM\Column(enumType: ModoCarteira::class)]
    private ModoCarteira $modo = ModoCarteira::Unico;

    /** Regra de honorários PADRÃO da carteira; o caso guarda o snapshot aplicado (SPEC §18.2). */
    #[ORM\Column(enumType: FormaHonorarios::class)]
    private FormaHonorarios $formaHonorarios = FormaHonorarios::SemPercentual;

    /** Percentual padrão de honorários (ex.: "10.00"); nulo quando não se aplica. */
    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    private ?string $percentualHonorarios = null;

    /** Dias de tolerância para alertas de atraso — não rompe acordos automaticamente (SPEC §12). */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $toleranciaAtrasoDias = 0;

    /** Tipo de vínculo normalmente cobrado — apenas sugestão, nunca decide (SPEC §8.5/8.6). */
    #[ORM\Column(enumType: TipoVinculo::class, nullable: true)]
    private ?TipoVinculo $tipoVinculoPreferido = null;

    /** Rótulo do objeto na interface (ex.: "Unidade", "Veículo"); domínio permanece genérico (SPEC §4). */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $rotuloObjeto = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $atualizadoEm = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $criadoPor = null;

    public function __construct()
    {
        $this->criadoEm = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function aoAtualizar(): void
    {
        $this->atualizadoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getCliente(): ?Cliente
    {
        return $this->cliente;
    }

    public function setCliente(Cliente $cliente): self
    {
        $this->cliente = $cliente;

        return $this;
    }

    public function getNome(): string
    {
        return $this->nome;
    }

    public function setNome(string $nome): self
    {
        $this->nome = $nome;

        return $this;
    }

    public function getModo(): ModoCarteira
    {
        return $this->modo;
    }

    public function setModo(ModoCarteira $modo): self
    {
        $this->modo = $modo;

        return $this;
    }

    public function getFormaHonorarios(): FormaHonorarios
    {
        return $this->formaHonorarios;
    }

    public function setFormaHonorarios(FormaHonorarios $formaHonorarios): self
    {
        $this->formaHonorarios = $formaHonorarios;

        return $this;
    }

    public function getPercentualHonorarios(): ?string
    {
        return $this->percentualHonorarios;
    }

    public function setPercentualHonorarios(?string $percentualHonorarios): self
    {
        $this->percentualHonorarios = $percentualHonorarios;

        return $this;
    }

    public function getToleranciaAtrasoDias(): int
    {
        return $this->toleranciaAtrasoDias;
    }

    public function setToleranciaAtrasoDias(int $toleranciaAtrasoDias): self
    {
        $this->toleranciaAtrasoDias = $toleranciaAtrasoDias;

        return $this;
    }

    public function getTipoVinculoPreferido(): ?TipoVinculo
    {
        return $this->tipoVinculoPreferido;
    }

    public function setTipoVinculoPreferido(?TipoVinculo $tipoVinculoPreferido): self
    {
        $this->tipoVinculoPreferido = $tipoVinculoPreferido;

        return $this;
    }

    public function getRotuloObjeto(): ?string
    {
        return $this->rotuloObjeto;
    }

    public function setRotuloObjeto(?string $rotuloObjeto): self
    {
        $this->rotuloObjeto = $rotuloObjeto;

        return $this;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getAtualizadoEm(): ?\DateTimeImmutable
    {
        return $this->atualizadoEm;
    }

    public function getCriadoPor(): ?User
    {
        return $this->criadoPor;
    }

    public function setCriadoPor(?User $criadoPor): self
    {
        $this->criadoPor = $criadoPor;

        return $this;
    }
}
