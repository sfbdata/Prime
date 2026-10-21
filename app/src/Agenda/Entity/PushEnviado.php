<?php

declare(strict_types=1);

namespace App\Agenda\Entity;

use App\Agenda\Repository\PushEnviadoRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/** Lembrete já enviado por push a uma pessoa (pela chave do lembrete): a varredura não repete o aviso. */
#[ORM\Entity(repositoryClass: PushEnviadoRepository::class)]
#[ORM\Table(name: 'agenda_push_enviado')]
#[ORM\UniqueConstraint(name: 'uniq_agenda_push_enviado', columns: ['usuario_id', 'chave'])]
class PushEnviado implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $enviadoEm;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'usuario_id', nullable: false, onDelete: 'CASCADE')]
        private User $usuario,
        #[ORM\Column(length: 120)]
        private string $chave,
    ) {
        $this->enviadoEm = new \DateTimeImmutable();
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }
    public function getChave(): string
    {
        return $this->chave;
    }
}
