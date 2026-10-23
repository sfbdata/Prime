<?php

declare(strict_types=1);

namespace App\Tarefa\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Repository\AprendizadoDaPendenciaRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma linha das pendências que a pessoa pediu ao passar a etapa adiante (desenho 04B de 22/10, L157-158 e L173;
 * spec `minhas-metas-esteira-2210.md`, 1.1 item 2 e Fase 4), pela etapa destino. Alimenta o "Você costuma pedir:" do
 * modal. Por usuário e escritório (C2). A linha fica fora da auditoria (AuditavelCoberturaTest) e sai quando a
 * pessoa apaga o que foi aprendido (D4).
 */
#[ORM\Entity(repositoryClass: AprendizadoDaPendenciaRepository::class)]
#[ORM\Table(name: 'tarefa_aprendizado_pendencia')]
#[ORM\Index(name: 'idx_tarefa_aprendizado_pendencia_dono', columns: ['tenant_id', 'usuario_id', 'criado_em'])]
class AprendizadoDaPendencia implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'usuario_id', nullable: false, onDelete: 'CASCADE')]
        private User $usuario,
        #[ORM\Column(name: 'etapa_destino', length: 20, enumType: EtapaDaPasta::class)]
        private EtapaDaPasta $etapaDestino,
        #[ORM\Column(type: 'text')]
        private string $linha,
        ?\DateTimeImmutable $criadoEm = null,
    ) {
        $this->criadoEm = $criadoEm ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function getUsuario(): User
    {
        return $this->usuario;
    }

    public function getEtapaDestino(): EtapaDaPasta
    {
        return $this->etapaDestino;
    }

    public function getLinha(): string
    {
        return $this->linha;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }
}
