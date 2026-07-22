<?php

declare(strict_types=1);

namespace App\Cobranca\Entity;

use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\RegimeJuros;
use App\Cobranca\Repository\ObjetoCobrancaRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Objeto de Cobrança (SPEC §4): o elemento ao qual a dívida está vinculada (unidade, sala,
 * veículo, imóvel, contrato, matrícula...). Pertence a uma Carteira; pode possuir vários casos
 * ao longo do tempo. O conceito é genérico — a UI usa o rótulo da carteira.
 *
 * Config de encargos — NÍVEL 2 (o "meio") da cascata AO VIVO (spec "cascata de encargos ao vivo sem
 * snapshot" §3.1): `Carteira → Objeto → Obrigação`. Substitui o CASO nessa posição — o
 * `ResolvedorConfigEncargos::resolverDoCaso` passou a delegar para `resolverDoObjeto`, e as colunas
 * de config do `CasoCobranca` viram sombra/mortas (mantidas por 1 release para rollback seguro, mas
 * não lidas). TODOS os campos nullable, espelhando 1:1 os da `Obrigacao`: null = "herda a carteira";
 * preenchido = override deste objeto (vale para TODAS as obrigações de TODOS os casos do objeto).
 * `taxaHonorariosBp` é BP (não forma+percentual): supersede a decisão D2 no nível do objeto — a
 * mesma amarração que a Obrigação já tem (spec "taxa por-obrigação").
 */
#[ORM\Entity(repositoryClass: ObjetoCobrancaRepository::class)]
#[ORM\Table(name: 'cobranca_objeto')]
#[ORM\HasLifecycleCallbacks]
class ObjetoCobranca implements TenantAware, Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: Carteira::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Carteira $carteira = null;

    /** Identificação do objeto no contexto da carteira (ex.: "Apto 402", placa, matrícula). */
    #[ORM\Column(length: 255)]
    private string $identificacao = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $descricao = null;

    /** Referência da fonte externa (importação), para deduplicação intra-tenant em reimportações. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $referenciaExterna = null;

    // ------------------------------------------------------------------------------------------
    // Configuração de encargos — NÍVEL 2 (meio) da cascata AO VIVO. TODOS nullable: null = "herda
    // a Carteira". Resolução campo a campo pelo `ResolvedorConfigEncargos::resolverDoObjeto`.
    // ------------------------------------------------------------------------------------------

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $taxaJurosMensalBp = null;

    #[ORM\Column(length: 20, enumType: RegimeJuros::class, nullable: true)]
    private ?RegimeJuros $regimeJuros = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $taxaMultaBp = null;

    #[ORM\Column(length: 20, enumType: BaseEncargo::class, nullable: true)]
    private ?BaseEncargo $baseMulta = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $taxaCorrecaoBp = null;

    #[ORM\Column(length: 20, enumType: BaseEncargo::class, nullable: true)]
    private ?BaseEncargo $baseCorrecao = null;

    /** Override da alíquota de honorários deste objeto, em bp (supersede D2, nível 2). null = herda a carteira. */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $taxaHonorariosBp = null;

    #[ORM\Column(length: 20, enumType: BaseEncargo::class, nullable: true)]
    private ?BaseEncargo $baseHonorarios = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $carenciaHonorariosDias = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $toleranciaJurosMultaDias = null;

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

    public function getCarteira(): ?Carteira
    {
        return $this->carteira;
    }

    public function setCarteira(Carteira $carteira): self
    {
        $this->carteira = $carteira;

        return $this;
    }

    public function getIdentificacao(): string
    {
        return $this->identificacao;
    }

    public function setIdentificacao(string $identificacao): self
    {
        $this->identificacao = $identificacao;

        return $this;
    }

    public function getDescricao(): ?string
    {
        return $this->descricao;
    }

    public function setDescricao(?string $descricao): self
    {
        $this->descricao = $descricao;

        return $this;
    }

    public function getReferenciaExterna(): ?string
    {
        return $this->referenciaExterna;
    }

    public function setReferenciaExterna(?string $referenciaExterna): self
    {
        $this->referenciaExterna = $referenciaExterna;

        return $this;
    }

    public function getTaxaJurosMensalBp(): ?int
    {
        return $this->taxaJurosMensalBp;
    }

    public function setTaxaJurosMensalBp(?int $taxaJurosMensalBp): self
    {
        $this->taxaJurosMensalBp = $taxaJurosMensalBp;

        return $this;
    }

    public function getRegimeJuros(): ?RegimeJuros
    {
        return $this->regimeJuros;
    }

    public function setRegimeJuros(?RegimeJuros $regimeJuros): self
    {
        $this->regimeJuros = $regimeJuros;

        return $this;
    }

    public function getTaxaMultaBp(): ?int
    {
        return $this->taxaMultaBp;
    }

    public function setTaxaMultaBp(?int $taxaMultaBp): self
    {
        $this->taxaMultaBp = $taxaMultaBp;

        return $this;
    }

    public function getBaseMulta(): ?BaseEncargo
    {
        return $this->baseMulta;
    }

    public function setBaseMulta(?BaseEncargo $baseMulta): self
    {
        $this->baseMulta = $baseMulta;

        return $this;
    }

    public function getTaxaCorrecaoBp(): ?int
    {
        return $this->taxaCorrecaoBp;
    }

    public function setTaxaCorrecaoBp(?int $taxaCorrecaoBp): self
    {
        $this->taxaCorrecaoBp = $taxaCorrecaoBp;

        return $this;
    }

    public function getBaseCorrecao(): ?BaseEncargo
    {
        return $this->baseCorrecao;
    }

    public function setBaseCorrecao(?BaseEncargo $baseCorrecao): self
    {
        $this->baseCorrecao = $baseCorrecao;

        return $this;
    }

    public function getTaxaHonorariosBp(): ?int
    {
        return $this->taxaHonorariosBp;
    }

    public function setTaxaHonorariosBp(?int $taxaHonorariosBp): self
    {
        $this->taxaHonorariosBp = $taxaHonorariosBp;

        return $this;
    }

    public function getBaseHonorarios(): ?BaseEncargo
    {
        return $this->baseHonorarios;
    }

    public function setBaseHonorarios(?BaseEncargo $baseHonorarios): self
    {
        $this->baseHonorarios = $baseHonorarios;

        return $this;
    }

    public function getCarenciaHonorariosDias(): ?int
    {
        return $this->carenciaHonorariosDias;
    }

    public function setCarenciaHonorariosDias(?int $carenciaHonorariosDias): self
    {
        $this->carenciaHonorariosDias = $carenciaHonorariosDias;

        return $this;
    }

    public function getToleranciaJurosMultaDias(): ?int
    {
        return $this->toleranciaJurosMultaDias;
    }

    public function setToleranciaJurosMultaDias(?int $toleranciaJurosMultaDias): self
    {
        $this->toleranciaJurosMultaDias = $toleranciaJurosMultaDias;

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
