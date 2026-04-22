<?php

namespace App\Entity\Ponto;

use App\Entity\Tenant\Tenant;
use App\Repository\Ponto\JornadaTenantRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: JornadaTenantRepository::class)]
class JornadaTenant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'jornadaTenant')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\Column]
    private int $cargaHorariaSemanal = 2400; // em minutos (40h)

    #[ORM\Column]
    private bool $alertaHabilitado = true;

    /**
     * @var Collection<int, BlocoJornada>
     */
    #[ORM\OneToMany(targetEntity: BlocoJornada::class, mappedBy: 'jornadaTenant', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $blocos;

    public function __construct()
    {
        $this->blocos = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(Tenant $tenant): static
    {
        $this->tenant = $tenant;
        return $this;
    }

    public function getCargaHorariaSemanal(): int
    {
        return $this->cargaHorariaSemanal;
    }

    public function setCargaHorariaSemanal(int $cargaHorariaSemanal): static
    {
        $this->cargaHorariaSemanal = $cargaHorariaSemanal;
        return $this;
    }

    public function isAlertaHabilitado(): bool
    {
        return $this->alertaHabilitado;
    }

    public function setAlertaHabilitado(bool $alertaHabilitado): static
    {
        $this->alertaHabilitado = $alertaHabilitado;
        return $this;
    }

    /**
     * @return Collection<int, BlocoJornada>
     */
    public function getBlocos(): Collection
    {
        return $this->blocos;
    }

    public function addBloco(BlocoJornada $bloco): static
    {
        if (!$this->blocos->contains($bloco)) {
            $this->blocos->add($bloco);
            $bloco->setJornadaTenant($this);
        }
        return $this;
    }

    public function removeBloco(BlocoJornada $bloco): static
    {
        $this->blocos->removeElement($bloco);
        return $this;
    }
}
