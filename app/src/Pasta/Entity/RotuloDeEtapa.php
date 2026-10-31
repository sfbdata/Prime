<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\RotuloDeEtapaRepository;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Nome dado pelo escritório à etapa entre duas fases da trilha do andamento ("Triagem" entre Abertura e Documentação;
 * desenho 02 de 31/10/2026). Vale para a pasta e a via (judicial ou administrativa) e é de todo o escritório. `origem` é o
 * índice da fase em que a etapa começa, o mesmo índice de {@see \App\Pasta\Service\AndamentoDaPasta::fases()}. Fica fora de
 * `pasta_andamento` de propósito: a maioria das pastas só tem fase ESTIMADA (sem linha lá), e criar a linha mudaria filtros.
 */
#[ORM\Entity(repositoryClass: RotuloDeEtapaRepository::class)]
#[ORM\Table(name: 'pasta_rotulo_etapa')]
#[ORM\UniqueConstraint(name: 'uniq_pasta_rotulo_etapa', columns: ['pasta_id', 'via', 'origem'])]
#[ORM\Index(name: 'idx_pasta_rotulo_etapa_tenant', columns: ['tenant_id'])]
class RotuloDeEtapa implements TenantAware, Auditavel
{
    public const TAMANHO_MAXIMO = 12;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'atualizado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $atualizadoEm;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: Pasta::class)]
        #[ORM\JoinColumn(name: 'pasta_id', nullable: false, onDelete: 'CASCADE')]
        private Pasta $pasta,
        #[ORM\Column(length: 20)]
        private string $via,
        #[ORM\Column(type: 'smallint')]
        private int $origem,
        #[ORM\Column(length: 12)]
        private string $texto,
    ) {
        $this->atualizadoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function getPasta(): Pasta
    {
        return $this->pasta;
    }

    public function getVia(): string
    {
        return $this->via;
    }

    public function getOrigem(): int
    {
        return $this->origem;
    }

    public function getTexto(): string
    {
        return $this->texto;
    }

    public function renomear(string $texto): void
    {
        $this->texto = $texto;
        $this->atualizadoEm = new \DateTimeImmutable();
    }
}
