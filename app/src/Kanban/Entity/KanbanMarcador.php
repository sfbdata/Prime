<?php

declare(strict_types=1);

namespace App\Kanban\Entity;

use App\Kanban\Repository\KanbanMarcadorRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: KanbanMarcadorRepository::class)]
#[ORM\Table(name: 'kanban_marcador')]
class KanbanMarcador
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $nome;

    #[ORM\Column(length: 7)]
    private string $cor;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?KanbanBoard $board = null;

    public function __construct(string $nome, string $cor, KanbanBoard $board)
    {
        $this->nome  = $nome;
        $this->cor   = $cor;
        $this->board = $board;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNome(): string
    {
        return $this->nome;
    }

    public function setNome(string $nome): static
    {
        $this->nome = $nome;

        return $this;
    }

    public function getCor(): string
    {
        return $this->cor;
    }

    public function setCor(string $cor): static
    {
        $this->cor = $cor;

        return $this;
    }

    public function getBoard(): ?KanbanBoard
    {
        return $this->board;
    }
}
