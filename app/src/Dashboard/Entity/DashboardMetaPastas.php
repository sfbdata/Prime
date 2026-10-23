<?php

declare(strict_types=1);

namespace App\Dashboard\Entity;

use App\Dashboard\Repository\DashboardMetaPastasRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Meta de pastas do escritório (DASH-016), a partir de um mês. Vale até ser trocada: a meta de um
 * mês é a linha mais recente com `mes` igual ou anterior a ele. Trocar a meta grava uma linha nova
 * (o histórico fica), nunca edita a antiga.
 *
 * `mes` é sempre o dia 1º do mês de vigência (o construtor normaliza).
 *
 * Anatomia:
 *   - índice (tenant_id, mes): a busca da meta vigente;
 *   - `definida_por_id` NOT NULL sem ação: o sistema não apaga usuário (só desativa);
 *   - `tenant_id` NOT NULL sem ação: TenantAware, apagada pela purga do escritório.
 */
#[ORM\Entity(repositoryClass: DashboardMetaPastasRepository::class)]
#[ORM\Table(name: 'dashboard_meta_pastas')]
#[ORM\Index(name: 'idx_dashboard_meta_pastas_tenant_mes', columns: ['tenant_id', 'mes'])]
#[ORM\Index(name: 'idx_dashboard_meta_pastas_definida_por', columns: ['definida_por_id'])]
class DashboardMetaPastas implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'mes', type: 'date_immutable')]
    private \DateTimeImmutable $mes;

    #[ORM\Column(name: 'criada_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadaEm;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        \DateTimeImmutable $mes,
        #[ORM\Column(name: 'valor', type: 'integer')]
        private int $valor,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'definida_por_id', nullable: false)]
        private User $definidaPor,
        ?\DateTimeImmutable $criadaEm = null,
    ) {
        self::exigirValorValido($valor);
        $this->mes      = $mes->setDate((int) $mes->format('Y'), (int) $mes->format('n'), 1)->setTime(0, 0);
        $this->criadaEm = $criadaEm ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getMes(): \DateTimeImmutable
    {
        return $this->mes;
    }

    public function getValor(): int
    {
        return $this->valor;
    }

    public function getDefinidaPor(): User
    {
        return $this->definidaPor;
    }

    public function getCriadaEm(): \DateTimeImmutable
    {
        return $this->criadaEm;
    }

    private static function exigirValorValido(int $valor): void
    {
        if ($valor < 0) {
            throw new \DomainException('A meta de pastas não pode ser negativa.');
        }
    }
}
