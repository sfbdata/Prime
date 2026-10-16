<?php

declare(strict_types=1);

namespace App\Central\Entity;

use App\Central\Repository\AvisoResolvidoRepository;
use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * "Dar baixa" num aviso do sino pela Central (F3, `docs/specs/central-comunicacao-f3-menu.md` §2; desenho
 * `resolverNotif`, L5603-5630). A baixa é da PESSOA, na Central: o sino não muda. Apagar a notificação apaga a baixa.
 */
#[ORM\Entity(repositoryClass: AvisoResolvidoRepository::class)]
#[ORM\Table(name: 'central_aviso_resolvido')]
#[ORM\Index(name: 'idx_central_aviso_resolvido_usuario', columns: ['usuario_id'])]
#[ORM\UniqueConstraint(name: 'uniq_central_aviso_resolvido', columns: ['notificacao_id'])]
class AvisoResolvido implements TenantAware
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
        #[ORM\JoinColumn(name: 'usuario_id', nullable: false, onDelete: 'CASCADE')]
        private User $usuario,
        #[ORM\ManyToOne(targetEntity: Notificacao::class)]
        #[ORM\JoinColumn(name: 'notificacao_id', nullable: false, onDelete: 'CASCADE')]
        private Notificacao $notificacao,
        #[ORM\Column(name: 'resolvida_em', type: 'datetime_immutable')]
        private \DateTimeImmutable $resolvidaEm,
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

    public function getNotificacao(): Notificacao
    {
        return $this->notificacao;
    }

    public function getResolvidaEm(): \DateTimeImmutable
    {
        return $this->resolvidaEm;
    }
}
