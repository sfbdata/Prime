<?php

declare(strict_types=1);

namespace App\Ponto\Entity;

use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * O contador do NSR (número sequencial do registro) de UM escritório: o último número entregue a uma batida
 * (spec ponto-desenho-0811, D2 e E15).
 *
 * Lido e gravado só por SQL, no `NumeroSequencialDoRegistro`, sempre com o tenant_id explícito e dentro da transação
 * da batida: o rollback da batida desfaz o número, então a sequência não tem buraco. A entidade existe para o
 * mapeamento conhecer a tabela (sincronia do schema); não há tela nem consulta ORM sobre ela. Nasce sem linhas: a
 * primeira batida com NSR do escritório cria a dele.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ponto_contador_nsr')]
#[ORM\UniqueConstraint(name: 'uniq_ponto_contador_nsr_tenant', columns: ['tenant_id'])]
class ContadorDeNsr implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\Column(type: 'bigint')]
        private int $ultimo,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function getUltimo(): int
    {
        return $this->ultimo;
    }
}
