<?php

declare(strict_types=1);

namespace App\Agenda\Entity;

use App\Agenda\Repository\ExclusaoNoGoogleRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/** Compromisso apagado aqui que ainda precisa ser apagado no Google da pessoa (gravado ao excluir, de qualquer tela). */
#[ORM\Entity(repositoryClass: ExclusaoNoGoogleRepository::class)]
#[ORM\Table(name: 'agenda_google_exclusao')]
#[ORM\UniqueConstraint(name: 'uniq_agenda_google_exclusao', columns: ['usuario_id', 'google_id'])]
class ExclusaoNoGoogle implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'usuario_id', nullable: false, onDelete: 'CASCADE')]
        private User $usuario,
        #[ORM\Column(name: 'google_id', length: 1024)]
        private string $googleId,
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

    public function getGoogleId(): string
    {
        return $this->googleId;
    }
}
