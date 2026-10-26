<?php

declare(strict_types=1);

namespace App\ServiceDesk\Entity;

use App\Entity\Tenant\Tenant;
use App\ServiceDesk\Exception\SaldoInsuficiente;
use App\ServiceDesk\Repository\SaldoDoEscritorioRepository;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Saldo do escritório para chamados do Service Desk (desenho 12). Em centavos inteiros.
 * É cache do razão (`MovimentoDeSaldo` confirmados): só muda pelos UseCases do razão, com a linha
 * travada (`SaldoDoEscritorioRepository::travarDoEscritorio`).
 */
#[ORM\Entity(repositoryClass: SaldoDoEscritorioRepository::class)]
#[ORM\Table(name: 'service_desk_saldo')]
#[ORM\UniqueConstraint(name: 'uniq_service_desk_saldo_tenant', columns: ['tenant_id'])]
class SaldoDoEscritorio implements TenantAware, Auditavel
{
    /** Saldo mínimo para abrir um chamado (R$ 20,00). */
    public const MINIMO_PARA_ABRIR_CENTAVOS = 2_000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'bigint')]
    private int $saldoCentavos = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $atualizadoEm;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Tenant $tenant,
    ) {
        $this->atualizadoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getSaldoCentavos(): int
    {
        return $this->saldoCentavos;
    }

    public function permiteAbrirChamado(): bool
    {
        return $this->saldoCentavos >= self::MINIMO_PARA_ABRIR_CENTAVOS;
    }

    public function creditar(int $valorCentavos): void
    {
        $this->exigirValorPositivo($valorCentavos);
        $this->saldoCentavos += $valorCentavos;
        $this->atualizadoEm = new \DateTimeImmutable();
    }

    public function debitar(int $valorCentavos): void
    {
        $this->exigirValorPositivo($valorCentavos);
        if ($valorCentavos > $this->saldoCentavos) {
            throw SaldoInsuficiente::para($this->saldoCentavos, $valorCentavos);
        }
        $this->saldoCentavos -= $valorCentavos;
        $this->atualizadoEm = new \DateTimeImmutable();
    }

    private function exigirValorPositivo(int $valorCentavos): void
    {
        if ($valorCentavos <= 0) {
            throw new \DomainException('O valor tem que ser maior que zero.');
        }
    }
}
