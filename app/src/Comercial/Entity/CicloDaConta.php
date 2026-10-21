<?php

declare(strict_types=1);

namespace App\Comercial\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\Mapping as ORM;

/**
 * O ciclo de uma pessoa num escritório para as automações (spec §3.4): quando confirmou, quando recebeu as
 * boas-vindas e as dicas. Deduplica `val`, `conv` e `dica` (uma boas-vindas por pessoa e escritório). `tenant_id`
 * null = conta de plataforma (convite de plataforma); um índice parcial por escopo.
 */
#[ORM\Entity]
#[ORM\Table(name: 'comercial_ciclo_conta')]
#[ORM\UniqueConstraint(name: 'uniq_comercial_ciclo_escritorio', columns: ['user_id', 'tenant_id'], options: ['where' => '(tenant_id IS NOT NULL)'])]
#[ORM\UniqueConstraint(name: 'uniq_comercial_ciclo_plataforma', columns: ['user_id'], options: ['where' => '(tenant_id IS NULL)'])]
#[ORM\Index(name: 'idx_comercial_ciclo_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_comercial_ciclo_confirmado', columns: ['confirmado_em'])]
class CicloDaConta
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', nullable: true)]
    private ?Tenant $tenant = null;

    #[ORM\Column(name: 'confirmado_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $confirmadoEm = null;

    #[ORM\Column(name: 'boas_vindas_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $boasVindasEm = null;

    #[ORM\Column(name: 'dica_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dicaEm = null;

    public function __construct(User $user)
    {
        $this->user = $user;
    }
}
