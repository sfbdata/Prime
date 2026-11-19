<?php

declare(strict_types=1);

namespace App\Contribua\Entity;

use App\Contribua\Repository\CampanhaAlteracaoRepository;
use App\Entity\Auth\User;
use App\Shared\Attribute\SemIsolamentoDeTenant;
use Doctrine\ORM\Mapping as ORM;

/** Trilha de auditoria da campanha (regra 8): quem mudou, quando, o que era e o que ficou. */
#[ORM\Entity(repositoryClass: CampanhaAlteracaoRepository::class)]
#[ORM\Table(name: 'contribua_campanha_alteracao')]
#[SemIsolamentoDeTenant('Trilha da campanha comercial do BlueJus, que não pertence a nenhum escritório; só o Master altera a campanha')]
class CampanhaAlteracao
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /**
     * @param array<string, mixed> $antes
     * @param array<string, mixed> $depois
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Campanha::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Campanha $campanha,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'usuario_id', nullable: true, onDelete: 'SET NULL')]
        private ?User $usuario,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $em,
        #[ORM\Column(type: 'json')]
        private array $antes,
        #[ORM\Column(type: 'json')]
        private array $depois,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCampanha(): Campanha
    {
        return $this->campanha;
    }

    /** @return array<string, mixed> */
    public function getAntes(): array
    {
        return $this->antes;
    }

    /** @return array<string, mixed> */
    public function getDepois(): array
    {
        return $this->depois;
    }

    public function getUsuario(): ?User
    {
        return $this->usuario;
    }

    public function getEm(): \DateTimeImmutable
    {
        return $this->em;
    }
}
