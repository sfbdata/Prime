<?php

declare(strict_types=1);

namespace App\Cobranca\Entity;

use App\Cobranca\Enum\TipoLiquidacao;
use App\Cobranca\Repository\LiquidacaoRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Liquidação de um Caso de Cobrança (SPEC §11): redução RECONHECIDA do saldo por bem móvel/imóvel,
 * direito ou outra forma aceita na negociação (tipicamente não monetária). Distinta do Pagamento —
 * que abate obrigações e rateia honorários (§18): a liquidação reduz o saldo diretamente, sem
 * rateio. Regra central — o valor ATRIBUÍDO ao bem e o valor RECONHECIDO para liquidação da dívida
 * podem ser DIFERENTES; o saldo é reduzido pelo `valorReconhecido`, nunca pelo `valorAtribuidoBem`.
 * Valores em CENTAVOS. Saldo derivado (invariável 20).
 */
#[ORM\Entity(repositoryClass: LiquidacaoRepository::class)]
#[ORM\Table(name: 'cobranca_liquidacao')]
#[ORM\Index(name: 'idx_cobranca_liquidacao_tenant_caso', columns: ['tenant_id', 'caso_id'])]
#[ORM\HasLifecycleCallbacks]
class Liquidacao implements TenantAware, Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: CasoCobranca::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?CasoCobranca $caso = null;

    #[ORM\Column(enumType: TipoLiquidacao::class)]
    private TipoLiquidacao $tipo = TipoLiquidacao::Dinheiro;

    #[ORM\Column(length: 255)]
    private string $descricaoBem = '';

    /** Valor atribuído ao bem/direito, em CENTAVOS (opcional; pode diferir do reconhecido, SPEC §11). */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $valorAtribuidoBem = null;

    /** Valor efetivamente reconhecido como extinto da dívida, em CENTAVOS — é o que reduz o saldo (SPEC §11). */
    #[ORM\Column(type: 'integer')]
    private int $valorReconhecido = 0;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $data;

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
        $this->data = new \DateTimeImmutable();
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

    public function getCaso(): ?CasoCobranca
    {
        return $this->caso;
    }

    public function setCaso(CasoCobranca $caso): self
    {
        $this->caso = $caso;

        return $this;
    }

    public function getTipo(): TipoLiquidacao
    {
        return $this->tipo;
    }

    public function setTipo(TipoLiquidacao $tipo): self
    {
        $this->tipo = $tipo;

        return $this;
    }

    public function getDescricaoBem(): string
    {
        return $this->descricaoBem;
    }

    public function setDescricaoBem(string $descricaoBem): self
    {
        $this->descricaoBem = $descricaoBem;

        return $this;
    }

    public function getValorAtribuidoBem(): ?int
    {
        return $this->valorAtribuidoBem;
    }

    public function setValorAtribuidoBem(?int $valorAtribuidoBem): self
    {
        $this->valorAtribuidoBem = $valorAtribuidoBem;

        return $this;
    }

    public function getValorReconhecido(): int
    {
        return $this->valorReconhecido;
    }

    public function setValorReconhecido(int $valorReconhecido): self
    {
        $this->valorReconhecido = $valorReconhecido;

        return $this;
    }

    public function getData(): \DateTimeImmutable
    {
        return $this->data;
    }

    public function setData(\DateTimeImmutable $data): self
    {
        $this->data = $data;

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
