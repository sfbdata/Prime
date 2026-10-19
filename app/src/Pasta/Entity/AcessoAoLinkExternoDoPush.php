<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\AcessoAoLinkExternoDoPushRepository;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um acesso à página pública do link externo do Push (só os que mostraram o andamento, estado `ok`).
 *
 * Sem IP nem user-agent (LGPD): o desenho só mostra contagem por dia e horários (C.7).
 */
#[ORM\Entity(repositoryClass: AcessoAoLinkExternoDoPushRepository::class)]
#[ORM\Table(name: 'push_link_externo_acesso')]
#[ORM\Index(name: 'idx_push_link_externo_acesso_link', columns: ['link_id', 'acessado_em'])]
#[ORM\Index(name: 'idx_push_link_externo_acesso_tenant', columns: ['tenant_id'])]
class AcessoAoLinkExternoDoPush implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: LinkExternoDoPush::class)]
        #[ORM\JoinColumn(name: 'link_id', nullable: false, onDelete: 'CASCADE')]
        private LinkExternoDoPush $link,
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\Column(name: 'acessado_em', type: 'datetime_immutable')]
        private \DateTimeImmutable $acessadoEm,
    ) {
    }

    public static function do(LinkExternoDoPush $link, \DateTimeImmutable $agora): self
    {
        return new self($link, $link->getTenant(), $agora);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLink(): LinkExternoDoPush
    {
        return $this->link;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getAcessadoEm(): \DateTimeImmutable
    {
        return $this->acessadoEm;
    }
}
