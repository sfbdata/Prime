<?php

declare(strict_types=1);

namespace App\Tenant\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use App\Tenant\Repository\ConfiguracaoDeEdicaoRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A opção do escritório "Usuário primário pode editar e excluir qualquer registro sem limite de tempo" (spec
 * `edicao-sem-prazo-master-primario.md` §3). Uma linha por escritório, criada na primeira escolha de um primário; sem
 * linha vale ligada. Auditável: é a regra do escritório, escolhida por alguém (padrão da `ConfiguracaoDePresenca`).
 */
#[ORM\Entity(repositoryClass: ConfiguracaoDeEdicaoRepository::class)]
#[ORM\Table(name: 'escritorio_configuracao_edicao')]
#[ORM\UniqueConstraint(name: 'uniq_escritorio_configuracao_edicao', columns: ['tenant_id'])]
class ConfiguracaoDeEdicao implements TenantAware, Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'primario_sem_prazo', type: 'boolean', options: ['default' => true])]
    private bool $primarioSemPrazo = true;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'alterado_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $alteradoPor = null;

    #[ORM\Column(name: 'alterado_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $alteradoEm = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function isPrimarioSemPrazo(): bool
    {
        return $this->primarioSemPrazo;
    }

    public function getAlteradoPor(): ?User
    {
        return $this->alteradoPor;
    }

    public function getAlteradoEm(): ?\DateTimeImmutable
    {
        return $this->alteradoEm;
    }

    public function alterar(bool $primarioSemPrazo, User $por, \DateTimeImmutable $agora): void
    {
        $this->primarioSemPrazo = $primarioSemPrazo;
        $this->alteradoPor = $por;
        $this->alteradoEm = $agora;
    }
}
