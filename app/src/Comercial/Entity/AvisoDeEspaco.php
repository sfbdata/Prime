<?php

declare(strict_types=1);

namespace App\Comercial\Entity;

use App\Entity\Tenant\Tenant;
use Doctrine\ORM\Mapping as ORM;

/**
 * A vez de cada aviso de espaço (spec comercial-e-emails.md §4): uma linha por escritório, chave (`e90`, `e100`,
 * `ped`), dia em America/Sao_Paulo e plano (vazio no `e90` e no `e100`). O UNIQUE nas quatro colunas é a
 * deduplicação: o insert que não entra quer dizer que aquele aviso já saiu hoje. Gravada só por SQL, no ouvinte.
 */
#[ORM\Entity]
#[ORM\Table(name: 'comercial_aviso_espaco')]
#[ORM\UniqueConstraint(name: 'uniq_comercial_aviso_espaco', columns: ['tenant_id', 'chave', 'dia', 'plano'])]
class AvisoDeEspaco
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
    private Tenant $tenant;

    #[ORM\Column(length: 10)]
    private string $chave;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dia;

    #[ORM\Column(length: 40, options: ['default' => ''])]
    private string $plano = '';

    #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    public function __construct(Tenant $tenant, string $chave, \DateTimeImmutable $dia, \DateTimeImmutable $criadoEm)
    {
        $this->tenant = $tenant;
        $this->chave = $chave;
        $this->dia = $dia;
        $this->criadoEm = $criadoEm;
    }
}
