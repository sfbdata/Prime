<?php

declare(strict_types=1);

namespace App\Indicacao\Entity;

use App\Entity\Auth\User;
use App\Indicacao\Repository\IndicacaoAlertaRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * "Disparo alto de convites" (spec `indicar-bluejus.md`, comportamento 10): gravado quando a contagem de 24h de uma
 * pessoa cruza o alerta. Os 3 últimos vão ao card do Master.
 */
#[ORM\Entity(repositoryClass: IndicacaoAlertaRepository::class)]
#[ORM\Table(name: 'indicacao_alerta')]
#[ORM\Index(name: 'idx_indicacao_alerta_em', columns: ['em'])]
class IndicacaoAlerta
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'indicado_por_id', nullable: false, onDelete: 'CASCADE')]
        private User $indicadoPor,
        #[ORM\Column(length: 255)]
        private string $texto,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $em,
    ) {
        $this->texto = mb_substr($texto, 0, 255);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIndicadoPor(): User
    {
        return $this->indicadoPor;
    }

    public function getTexto(): string
    {
        return $this->texto;
    }

    public function getEm(): \DateTimeImmutable
    {
        return $this->em;
    }
}
