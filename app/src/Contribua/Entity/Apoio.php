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
 * pagou e o Master confere no extrato. A confirmação pelo banco (Cora, C4) preenche `confirmadoEm`
 * e libera o prêmio do apoiador.
 */
#[ORM\Entity(repositoryClass: ApoioRepository::class)]
#[ORM\Table(name: 'contribua_apoio')]
#[ORM\Index(name: 'idx_contribua_apoio_tenant_criado', columns: ['tenant_id', 'criado_em'])]
#[ORM\Index(name: 'idx_contribua_apoio_informado_por', columns: ['informado_por_id'])]
class Apoio implements TenantAware, Auditavel
{
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
}
