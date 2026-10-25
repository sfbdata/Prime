<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Entity;

use App\Entity\Auth\User;
use App\TrabalhoExterno\Repository\RecusaDeOfertaRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * "Recusar" a oferta com tempo (desenho 06 de 24/10, `recusar`, l.1169): quem recusou a vaga, uma vez por vaga
 * e usuário (`v.recusas`). Some com a vaga e com a conta (CASCADE). "Ofertar novamente" não zera (P7); "Você
 * recusou · aceitar mesmo assim" apaga a linha antes de candidatar.
 */
#[ORM\Entity(repositoryClass: RecusaDeOfertaRepository::class)]
#[ORM\Table(name: 'trabalho_externo_vaga_recusa')]
#[ORM\UniqueConstraint(name: 'uniq_te_vaga_recusa_vaga_usuario', columns: ['vaga_id', 'usuario_id'])]
class RecusaDeOferta
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Vaga::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Vaga $vaga,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $usuario,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $em,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVaga(): Vaga
    {
        return $this->vaga;
    }

    public function getUsuario(): User
    {
        return $this->usuario;
    }

    public function getEm(): \DateTimeImmutable
    {
        return $this->em;
    }
}
