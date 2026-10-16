<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Entity;

use App\Entity\Auth\User;
use App\TrabalhoExterno\DTO\Autor;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Enum\TipoMensagem;
use App\TrabalhoExterno\Repository\MensagemDemandaRepository;
use Doctrine\ORM\Mapping as ORM;

/** Mensagem do chat da demanda (comum, nota interna ou registro automático). */
#[ORM\Entity(repositoryClass: MensagemDemandaRepository::class)]
#[ORM\Table(name: 'trabalho_externo_mensagem')]
#[ORM\Index(name: 'idx_te_mensagem_demanda', columns: ['demanda_id', 'id'])]
class MensagemDemanda
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
        #[ORM\ManyToOne(targetEntity: DemandaExterna::class, inversedBy: 'mensagens')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private DemandaExterna $demanda,
        Autor $autor,
        #[ORM\Column(enumType: TipoMensagem::class, length: 20)]
        private TipoMensagem $tipo,
        #[ORM\Column(type: 'text')]
        private string $texto,
    ) {
        $this->autor = $autor->usuario;
        $this->nomeAutor = $autor->nome;
        $this->perfilAutor = $autor->perfil;
        $this->em = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAutor(): ?User
    {
        return $this->autor;
    }

    public function getNomeAutor(): string
    {
        return $this->nomeAutor;
    }

    public function getPerfilAutor(): Perfil
    {
        return $this->perfilAutor;
    }

    public function getTipo(): TipoMensagem
    {
        return $this->tipo;
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
