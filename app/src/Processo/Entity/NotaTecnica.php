<?php

declare(strict_types=1);

namespace App\Processo\Entity;

use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Processo\Repository\NotaTecnicaRepository;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Nota técnica: o texto do advogado sobre um PROCESSO — "o que este despacho significa, o que
 * precisa ser feito, prazo" (desenho 1.2.3 do Claude Designer, decisão do dono em 2026-10-05).
 *
 * A dona é sempre o processo: a nota aparece em TODA pasta que o vincula, e some com ele
 * (CASCADE). Opcionalmente fica pendurada numa movimentação do Push Processual
 * (`publicacaoDjen`); se a publicação sumir, a nota continua, só perde o gancho (SET NULL).
 * O autor também é SET NULL: desvincular o colaborador do escritório não apaga o que ele
 * escreveu sobre o caso.
 *
 * Edição/exclusão: só o autor, na janela da `JanelaDeEdicaoDeComentario` (15 min) — a mesma
 * regra das observações da pasta. O conteúdo é HTML do editor rico, limpo pelo
 * `SanitizadorTextoRico` ANTES de chegar aqui (o UseCase é a porta).
 */
#[ORM\Entity(repositoryClass: NotaTecnicaRepository::class)]
#[ORM\Table(name: 'nota_tecnica')]
#[ORM\Index(name: 'idx_nota_tecnica_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_nota_tecnica_processo', columns: ['processo_id'])]
#[ORM\Index(name: 'idx_nota_tecnica_publicacao', columns: ['publicacao_djen_id'])]
class NotaTecnica implements Auditavel, TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: Processo::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Processo $processo;

    #[ORM\ManyToOne(targetEntity: PublicacaoDjen::class)]
    #[ORM\JoinColumn(name: 'publicacao_djen_id', nullable: true, onDelete: 'SET NULL')]
    private ?PublicacaoDjen $publicacaoDjen;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $autor;

    #[ORM\Column(type: 'text')]
    private string $conteudo;

    #[ORM\Column(name: 'criada_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadaEm;

    #[ORM\Column(name: 'editada_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $editadaEm = null;

    public function __construct(
        Tenant $tenant,
        Processo $processo,
        User $autor,
        string $conteudo,
        ?PublicacaoDjen $publicacaoDjen = null,
    ) {
        $this->tenant         = $tenant;
        $this->processo       = $processo;
        $this->autor          = $autor;
        $this->conteudo       = $conteudo;
        $this->publicacaoDjen = $publicacaoDjen;
        $this->criadaEm       = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function getProcesso(): Processo
    {
        return $this->processo;
    }

    public function getPublicacaoDjen(): ?PublicacaoDjen
    {
        return $this->publicacaoDjen;
    }

    public function getAutor(): ?User
    {
        return $this->autor;
    }

    public function getConteudo(): string
    {
        return $this->conteudo;
    }

    /** Conteúdo JÁ sanitizado — quem chama é o UseCase de edição, depois do `SanitizadorTextoRico`. */
    public function editar(string $conteudo): void
    {
        $this->conteudo  = $conteudo;
        $this->editadaEm = new \DateTimeImmutable();
    }

    public function getCriadaEm(): \DateTimeImmutable
    {
        return $this->criadaEm;
    }

    public function getEditadaEm(): ?\DateTimeImmutable
    {
        return $this->editadaEm;
    }

    /**
     * Indica se a nota pertence ao usuário informado (comparação por instância e, como reforço,
     * por id — útil quando há proxies do ORM). Nota sem autor (colaborador desvinculado) não
     * pertence a ninguém.
     */
    public function pertenceAo(User $user): bool
    {
        if ($this->autor === null) {
            return false;
        }

        return $this->autor === $user
            || ($this->autor->getId() !== null && $this->autor->getId() === $user->getId());
    }
}
