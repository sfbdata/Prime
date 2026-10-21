<?php

declare(strict_types=1);

namespace App\Indicacao\Entity;

use App\Entity\Auth\User;
use App\Indicacao\Repository\IndicacaoPausaRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A pausa de uma pessoa (spec `indicar-bluejus.md`): a linha existe enquanto ela está pausada; despausar apaga.
 */
#[ORM\Entity(repositoryClass: IndicacaoPausaRepository::class)]
#[ORM\Table(name: 'indicacao_pausa')]
class IndicacaoPausa
{
    public function __construct(
        #[ORM\Id]
        #[ORM\OneToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'pausado_por_id', nullable: true, onDelete: 'SET NULL')]
        private ?User $pausadoPor,
        #[ORM\Column(name: 'pausado_em', type: 'datetime_immutable')]
        private \DateTimeImmutable $pausadoEm,
    ) {
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getPausadoPor(): ?User
    {
        return $this->pausadoPor;
    }

    public function getPausadoEm(): \DateTimeImmutable
    {
        return $this->pausadoEm;
    }
}
