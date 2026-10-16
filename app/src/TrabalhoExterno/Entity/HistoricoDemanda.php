<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Entity;

use App\Entity\Auth\User;
use App\TrabalhoExterno\DTO\Autor;
use App\TrabalhoExterno\Enum\Perfil;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma linha do "REGISTRO COMPLETO": de que situação para qual, quem, quando e o motivo. `para`
 * guarda o valor da `Situacao` ou o marco `pago`; `de` é nulo no marco e na criação.
 */
#[ORM\Entity]
#[ORM\Table(name: 'trabalho_externo_historico')]
class HistoricoDemanda
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $autor;

    #[ORM\Column(length: 255)]
    private string $nomeAutor;

    #[ORM\Column(enumType: Perfil::class, length: 20)]
    private Perfil $perfilAutor;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $em;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: DemandaExterna::class, inversedBy: 'historico')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private DemandaExterna $demanda,
        #[ORM\Column(length: 20, nullable: true)]
        private ?string $de,
        #[ORM\Column(length: 20)]
        private string $para,
        Autor $autor,
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $motivo,
    ) {
        $this->autor = $autor->usuario;
        $this->nomeAutor = $autor->nome;
        $this->perfilAutor = $autor->perfil;
        $this->em = new \DateTimeImmutable();
    }

    public function getDe(): ?string
    {
        return $this->de;
    }

    public function getPara(): string
    {
        return $this->para;
    }

    public function getNomeAutor(): string
    {
        return $this->nomeAutor;
    }

    public function getPerfilAutor(): Perfil
    {
        return $this->perfilAutor;
    }

    public function getEm(): \DateTimeImmutable
    {
        return $this->em;
    }

    public function getMotivo(): ?string
    {
        return $this->motivo;
    }

    public function getDemanda(): DemandaExterna
    {
        return $this->demanda;
    }
}
