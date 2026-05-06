<?php

declare(strict_types=1);

namespace App\Kanban\Entity;

use App\Kanban\Repository\KanbanColunaRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: KanbanColunaRepository::class)]
#[ORM\Table(name: 'kanban_coluna')]
class KanbanColuna
{
    public const TIPO_PENDENCIAS = 'pendencias';
    public const TIPO_A_FAZER    = 'a_fazer';
    public const TIPO_FAZENDO    = 'fazendo';
    public const TIPO_FEITO      = 'feito';

    public const COLUNAS_PADRAO = [
        ['tipo' => self::TIPO_PENDENCIAS, 'nome' => 'Pendências', 'posicao' => 0],
        ['tipo' => self::TIPO_A_FAZER,    'nome' => 'A Fazer',    'posicao' => 1],
        ['tipo' => self::TIPO_FAZENDO,    'nome' => 'Fazendo',    'posicao' => 2],
        ['tipo' => self::TIPO_FEITO,      'nome' => 'Feito',      'posicao' => 3],
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $nome;

    #[ORM\Column(length: 20)]
    private string $tipo;

    #[ORM\Column(type: 'integer')]
    private int $posicao = 0;

    #[ORM\ManyToOne(inversedBy: 'colunas')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?KanbanBoard $board = null;

    #[ORM\OneToMany(mappedBy: 'coluna', targetEntity: KanbanCard::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['posicao' => 'ASC'])]
    private Collection $cards;

    public function __construct(string $nome, string $tipo, int $posicao, KanbanBoard $board)
    {
        $this->nome    = $nome;
        $this->tipo    = $tipo;
        $this->posicao = $posicao;
        $this->board   = $board;
        $this->cards   = new ArrayCollection();
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

    public function getTipo(): string
    {
        return $this->tipo;
    }

    public function getPosicao(): int
    {
        return $this->posicao;
    }

    public function getBoard(): ?KanbanBoard
    {
        return $this->board;
    }

    public function getCards(): Collection
    {
        return $this->cards;
    }

    public function contarCards(): int
    {
        return $this->cards->count();
    }
}
