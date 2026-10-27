<?php

declare(strict_types=1);

namespace App\ServiceDesk\Entity;

use App\Entity\Tenant\Tenant;
use App\ServiceDesk\Repository\HistoricoDoChamadoRepository;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/** Linha do ANDAMENTO do chamado (desenho 12, "Chamado #0001"): "Chamado aberto por fulano", mudanças de situação. */
#[ORM\Entity(repositoryClass: HistoricoDoChamadoRepository::class)]
#[ORM\Table(name: 'service_desk_chamado_historico')]
#[ORM\Index(name: 'idx_service_desk_historico_chamado', columns: ['chamado_id', 'criado_em'])]
class HistoricoDoChamado implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: ChamadoDeSuporte::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private ChamadoDeSuporte $chamado,
        #[ORM\Column(length: 255)]
        private string $texto,
    ) {
        $this->criadoEm = new \DateTimeImmutable();
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getChamado(): ChamadoDeSuporte
    {
        return $this->chamado;
    }

    public function getTexto(): string
    {
        return $this->texto;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }
}
