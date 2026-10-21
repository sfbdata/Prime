<?php

declare(strict_types=1);

namespace App\Tarefa\Entity;

use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * O contador da sequência de metas de UM escritório: o último número já entregue.
 *
 * Existe porque derivar o próximo do MAX(numero) reaproveitaria o número da última meta excluída (a
 * exclusão é real, ver `ExcluirTarefaUseCase`), o mesmo limite registrado na numeração de pasta. O
 * contador só anda para a frente, então "nunca reaproveita" vale também para o topo.
 *
 * Lido e gravado só por SQL, no `NumeracaoDeMetas` (serviço), sempre com o tenant_id explícito e sob a
 * trava por escritório. A entidade existe para o mapeamento conhecer a tabela (sincronia do schema);
 * não há tela nem consulta ORM sobre ela. Nasce sem linhas: a primeira meta numerada do escritório cria
 * a dele.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tarefa_numeracao')]
#[ORM\UniqueConstraint(name: 'uniq_tarefa_numeracao_tenant', columns: ['tenant_id'])]
class NumeracaoDeMetas implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
    private Tenant $tenant;

    #[ORM\Column(name: 'ultimo_numero', type: 'integer')]
    private int $ultimoNumero;

    public function __construct(Tenant $tenant, int $ultimoNumero)
    {
        $this->tenant = $tenant;
        $this->ultimoNumero = $ultimoNumero;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function getUltimoNumero(): int
    {
        return $this->ultimoNumero;
    }
}
