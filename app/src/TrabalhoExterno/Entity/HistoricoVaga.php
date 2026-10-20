<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Entity;

use App\TrabalhoExterno\Repository\HistoricoVagaRepository;
use Doctrine\ORM\Mapping as ORM;

/** Uma linha do "HISTÓRICO DA OFERTA" (`tv.hist` do desenho): ícone, cor, texto e quando. */
#[ORM\Entity(repositoryClass: HistoricoVagaRepository::class)]
#[ORM\Table(name: 'trabalho_externo_vaga_historico')]
class HistoricoVaga
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Vaga::class, inversedBy: 'historico')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Vaga $vaga,
        #[ORM\Column(length: 40)]
        private string $icone,
        #[ORM\Column(length: 9)]
        private string $cor,
        #[ORM\Column(length: 255)]
        private string $texto,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $em,
    ) {
    }

    public function getVaga(): Vaga
    {
        return $this->vaga;
    }

    public function getIcone(): string
    {
        return $this->icone;
    }

    public function getCor(): string
    {
        return $this->cor;
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
