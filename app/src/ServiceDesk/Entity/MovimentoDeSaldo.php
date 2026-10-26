<?php

declare(strict_types=1);

namespace App\ServiceDesk\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\ServiceDesk\Enum\FormaDeRecarga;
use App\ServiceDesk\Enum\TipoDeMovimento;
use App\ServiceDesk\Repository\MovimentoDeSaldoRepository;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Linha do razão do saldo. Imutável salvo a confirmação: crédito de recarga nasce pendente
 * (`confirmadoEm` nulo, "Aguardando" no extrato) e só vira saldo ao ser confirmado.
 * Débito e reembolso nascem confirmados.
 */
#[ORM\Entity(repositoryClass: MovimentoDeSaldoRepository::class)]
#[ORM\Table(name: 'service_desk_movimento')]
#[ORM\Index(name: 'idx_service_desk_movimento_tenant_criado', columns: ['tenant_id', 'criado_em'])]
#[ORM\UniqueConstraint(name: 'uniq_service_desk_movimento_referencia', columns: ['referencia'])]
#[ORM\UniqueConstraint(name: 'uniq_service_desk_movimento_id_externo', columns: ['id_externo'])]
class MovimentoDeSaldo implements TenantAware, Auditavel
{
    /** Recarga: de R$ 20,00 (mínimo do desenho) a R$ 10.000,00 (acima disso é engano de digitação). */
    public const RECARGA_MINIMA_CENTAVOS = 2_000;
    public const RECARGA_MAXIMA_CENTAVOS = 1_000_000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $confirmadoEm = null;

    /** Id do pagamento na operadora (end_to_end_id do PIX): garante que o mesmo pagamento não credite duas recargas. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $idExterno = null;

    /** Quem confirmou: 'manual' (Master) ou o provedor ('cora'...). Preenchido na confirmação. */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $origemDaConfirmacao = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $confirmadoPor = null;

    /** Valor que a operadora disse ter recebido quando difere do pedido (recarga segue pendente, para conciliar). */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $valorRecebidoCentavos = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $divergenteEm = null;

    private function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Tenant $tenant,
        #[ORM\Column(enumType: TipoDeMovimento::class, length: 20)]
        private TipoDeMovimento $tipo,
        #[ORM\Column(type: 'integer')]
        private int $valorCentavos,
        #[ORM\Column(length: 255)]
        private string $motivo,
        #[ORM\Column(length: 64, nullable: true)]
        private ?string $referencia,
        #[ORM\Column(enumType: FormaDeRecarga::class, length: 10, nullable: true)]
        private ?FormaDeRecarga $forma,
        #[ORM\Column(type: 'integer', nullable: true)]
        private ?int $chamadoId,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?User $autor,
    ) {
        if ($valorCentavos <= 0) {
            throw new \DomainException('O valor tem que ser maior que zero.');
        }
        $this->criadoEm = new \DateTimeImmutable();
    }

    public static function recargaPendente(Tenant $tenant, int $valorCentavos, string $referencia, FormaDeRecarga $forma, string $motivo, ?User $autor): self
    {
        if ($valorCentavos < self::RECARGA_MINIMA_CENTAVOS || $valorCentavos > self::RECARGA_MAXIMA_CENTAVOS) {
            throw new \DomainException('Informe um valor de recarga entre R$ 20,00 e R$ 10.000,00.');
        }

        return new self($tenant, TipoDeMovimento::Credito, $valorCentavos, $motivo, $referencia, $forma, null, $autor);
    }

    public static function debitoDeChamado(Tenant $tenant, int $valorCentavos, int $chamadoId, string $motivo, ?User $autor): self
    {
        $movimento = new self($tenant, TipoDeMovimento::Debito, $valorCentavos, $motivo, null, null, $chamadoId, $autor);
        $movimento->confirmadoEm = $movimento->criadoEm;

        return $movimento;
    }

    public static function reembolsoDeChamado(Tenant $tenant, int $valorCentavos, int $chamadoId, string $motivo, ?User $autor): self
    {
        $movimento = new self($tenant, TipoDeMovimento::Reembolso, $valorCentavos, $motivo, null, null, $chamadoId, $autor);
        $movimento->confirmadoEm = $movimento->criadoEm;

        return $movimento;
    }

    public function confirmar(?string $idExterno, string $origem, ?User $confirmadoPor): void
    {
        if (!$this->estaPendente()) {
            throw new \DomainException('Este movimento já está confirmado.');
        }
        $this->confirmadoEm = new \DateTimeImmutable();
        $this->idExterno = $idExterno;
        $this->origemDaConfirmacao = $origem;
        $this->confirmadoPor = $confirmadoPor;
    }

    /** A operadora recebeu outro valor: guarda para o Master conciliar e mantém a recarga pendente. */
    public function registrarDivergencia(int $valorRecebidoCentavos): void
    {
        if (!$this->estaPendente()) {
            throw new \DomainException('Este movimento já está confirmado.');
        }
        $this->valorRecebidoCentavos = $valorRecebidoCentavos;
        $this->divergenteEm = new \DateTimeImmutable();
    }

    public function temDivergencia(): bool
    {
        return null !== $this->divergenteEm;
    }

    public function estaPendente(): bool
    {
        return null === $this->confirmadoEm;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getTipo(): TipoDeMovimento
    {
        return $this->tipo;
    }

    public function getValorCentavos(): int
    {
        return $this->valorCentavos;
    }

    public function getMotivo(): string
    {
        return $this->motivo;
    }

    public function getReferencia(): ?string
    {
        return $this->referencia;
    }

    public function getChamadoId(): ?int
    {
        return $this->chamadoId;
    }

    public function getAutor(): ?User
    {
        return $this->autor;
    }

    public function getForma(): ?FormaDeRecarga
    {
        return $this->forma;
    }

    public function getOrigemDaConfirmacao(): ?string
    {
        return $this->origemDaConfirmacao;
    }

    public function getConfirmadoPor(): ?User
    {
        return $this->confirmadoPor;
    }

    public function getValorRecebidoCentavos(): ?int
    {
        return $this->valorRecebidoCentavos;
    }

    public function getDivergenteEm(): ?\DateTimeImmutable
    {
        return $this->divergenteEm;
    }

    public function getIdExterno(): ?string
    {
        return $this->idExterno;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getConfirmadoEm(): ?\DateTimeImmutable
    {
        return $this->confirmadoEm;
    }
}
