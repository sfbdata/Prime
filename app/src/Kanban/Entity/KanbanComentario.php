<?php

declare(strict_types=1);

namespace App\Kanban\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Kanban\Repository\KanbanComentarioRepository;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: KanbanComentarioRepository::class)]
#[ORM\Table(name: 'kanban_comentario')]
#[ORM\HasLifecycleCallbacks]
class KanbanComentario implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'text')]
    private string $conteudo;

    /**
     * Denormalizado do card dono, para o TenantFilter alcancar esta entidade. Nunca recebido
     * por parametro: o construtor deriva do pai, e por isso nao existe caminho para divergir.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(inversedBy: 'comentarios')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?KanbanCard $card = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $criadoPor = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $criadoEm = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $atualizadoEm = null;

    public function __construct(string $conteudo, KanbanCard $card, User $criadoPor)
    {
        $this->conteudo  = $conteudo;
        $this->card      = $card;

        $tenant = $card->getTenant();
        if ($tenant === null) {
            throw new \InvalidArgumentException(
                'Nao e possivel criar KanbanComentario a partir de um card sem escritorio.'
            );
        }
        $this->tenant = $tenant;
        $this->criadoPor = $criadoPor;
    }

    #[ORM\PrePersist]
    public function aoPersistir(): void
    {
        $this->criadoEm = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function aoAtualizar(): void
    {
        $this->atualizadoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConteudo(): string
    {
        return $this->conteudo;
    }

    public function setConteudo(string $conteudo): static
    {
        $this->conteudo = $conteudo;

        return $this;
    }

    public function getCard(): ?KanbanCard
    {
        return $this->card;
    }

    public function getCriadoPor(): ?User
    {
        return $this->criadoPor;
    }

    public function getCriadoEm(): ?\DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getAtualizadoEm(): ?\DateTimeImmutable
    {
        return $this->atualizadoEm;
    }

    public function pertenceAo(User $user): bool
    {
        return $this->criadoPor === $user;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }
}
