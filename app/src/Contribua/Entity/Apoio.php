<?php

declare(strict_types=1);

namespace App\Contribua\Entity;

use App\Contribua\Repository\ApoioRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma contribuição voluntária por PIX (desenho 08, "Prefere apoiar sem mudar de plano?").
 *
 * Na C2 só existe o apoio INFORMADO ("Já contribuí, registrar meu apoio"): o escritório diz que
 * pagou e o Master confere no extrato. Na C4 o banco (Cora) confirma o PIX da cobrança da conta: o apoio
 * nasce confirmado (`confirmadoPeloBanco`) e libera o prêmio do apoiador.
 */
#[ORM\Entity(repositoryClass: ApoioRepository::class)]
#[ORM\Table(name: 'contribua_apoio')]
#[ORM\Index(name: 'idx_contribua_apoio_tenant_criado', columns: ['tenant_id', 'criado_em'])]
#[ORM\Index(name: 'idx_contribua_apoio_informado_por', columns: ['informado_por_id'])]
#[ORM\UniqueConstraint(name: 'uniq_contribua_apoio_id_cora', columns: ['id_cora'])]
class Apoio implements TenantAware, Auditavel
{
    public const ORIGEM_INFORMADO = 'informado';
    public const ORIGEM_CORA = 'cora';

    /** R$ 1.000.000,00: acima disso é engano de digitação (e passaria do inteiro da coluna). */
    public const VALOR_MAXIMO_CENTAVOS = 100_000_000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $confirmadoEm = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    /** De onde veio: "informado" (Já contribuí) ou o banco que confirmou ("cora"). */
    #[ORM\Column(length: 20, options: ['default' => self::ORIGEM_INFORMADO])]
    private string $origem = self::ORIGEM_INFORMADO;

    /** Id da cobrança na Cora (`inv_...`): único, então a mesma confirmação não vira dois apoios. */
    #[ORM\Column(name: 'id_cora', length: 120, nullable: true)]
    private ?string $idCora = null;

    /** Nosso código da cobrança (`BJ<conta>-<n>`), o que identifica a conta no extrato. */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $txid = null;

    /** Quando alguém da conta viu o prêmio do apoiador (desenho 08): aparece uma vez por apoio confirmado. */
    #[ORM\Column(name: 'premio_visto_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $premioVistoEm = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?User $informadoPor,
        #[ORM\Column(type: 'integer')]
        private int $valorCentavos,
    ) {
        if ($valorCentavos < 0 || $valorCentavos > self::VALOR_MAXIMO_CENTAVOS) {
            throw new \DomainException('Informe um valor válido.');
        }
        $this->criadoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getValorCentavos(): int
    {
        return $this->valorCentavos;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getConfirmadoEm(): ?\DateTimeImmutable
    {
        return $this->confirmadoEm;
    }

    /** Apoio confirmado pelo banco (C4): nasce já confirmado, com o id da cobrança e o nosso código. */
    public static function confirmadoPeloBanco(Tenant $tenant, int $valorCentavos, string $origem, string $idNoBanco, string $txid, \DateTimeImmutable $pagoEm): self
    {
        $apoio = new self($tenant, null, $valorCentavos);
        $apoio->origem = $origem;
        $apoio->idCora = mb_substr($idNoBanco, 0, 120);
        $apoio->txid = mb_substr($txid, 0, 60);
        $apoio->confirmadoEm = $pagoEm;

        return $apoio;
    }

    /**
     * O banco confirmou o pagamento que a conta já tinha informado ("Já contribuí"): o mesmo apoio passa a
     * confirmado, com o valor que o banco recebeu. Um pagamento, um apoio.
     */
    public function confirmarPeloBanco(int $valorCentavos, string $origem, string $idNoBanco, string $txid, \DateTimeImmutable $pagoEm): void
    {
        $this->valorCentavos = $valorCentavos;
        $this->origem = $origem;
        $this->idCora = mb_substr($idNoBanco, 0, 120);
        $this->txid = mb_substr($txid, 0, 60);
        $this->confirmadoEm = $pagoEm;
    }

    public function marcarPremioVisto(\DateTimeImmutable $quando): void
    {
        $this->premioVistoEm ??= $quando;
    }

    public function getOrigem(): string
    {
        return $this->origem;
    }

    public function getIdCora(): ?string
    {
        return $this->idCora;
    }

    public function getTxid(): ?string
    {
        return $this->txid;
    }

    public function getPremioVistoEm(): ?\DateTimeImmutable
    {
        return $this->premioVistoEm;
    }
}
