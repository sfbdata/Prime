<?php

declare(strict_types=1);

namespace App\Profile\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Profile\Repository\ResumoDiarioEnvioRepository;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um resumo diário enviado (spec docs/specs/resumo-diario-por-email.md): uma linha por pessoa, escritório e dia. É a
 * trava contra o envio em dobro (o Carteiro não deduplica): a gravação vem antes do e-mail, por `INSERT ... ON
 * CONFLICT DO NOTHING`, e quem não gravou não envia.
 *
 * Anatomia: UNIQUE (tenant_id, user_id, dia); `user_id` CASCADE (o registro não sobrevive ao usuário); `tenant_id`
 * NOT NULL sem ação (a purga do escritório apaga explicitamente, `PurgarEscritorioUseCase::ORDEM_DELECAO`).
 */
#[ORM\Entity(repositoryClass: ResumoDiarioEnvioRepository::class)]
#[ORM\Table(name: 'resumo_diario_envio')]
#[ORM\UniqueConstraint(name: 'uniq_resumo_diario_envio', columns: ['tenant_id', 'user_id', 'dia'])]
#[ORM\Index(name: 'idx_resumo_diario_envio_user', columns: ['user_id'])]
class ResumoDiarioEnvio implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
        private User $usuario,
        #[ORM\Column(type: 'date_immutable')]
        private \DateTimeImmutable $dia,
        #[ORM\Column(name: 'enviado_em', type: 'datetime_immutable')]
        private \DateTimeImmutable $enviadoEm,
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

    public function getUsuario(): User
    {
        return $this->usuario;
    }

    public function getDia(): \DateTimeImmutable
    {
        return $this->dia;
    }

    public function getEnviadoEm(): \DateTimeImmutable
    {
        return $this->enviadoEm;
    }
}
