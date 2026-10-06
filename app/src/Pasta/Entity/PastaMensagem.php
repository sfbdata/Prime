<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaMensagemRepository;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PastaMensagemRepository::class)]
#[ORM\Table(name: 'pasta_mensagem')]
#[ORM\Index(name: 'idx_pasta_mensagem_pasta_id', columns: ['pasta_id'])]
#[ORM\Index(name: 'idx_pasta_mensagem_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_pasta_mensagem_resposta_a', columns: ['resposta_a_id'])]
class PastaMensagem implements Auditavel, TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Pasta::class, inversedBy: 'mensagens')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Pasta $pasta = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $autor = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[Assert\NotBlank(message: 'O conteúdo é obrigatório.')]
    #[ORM\Column(type: 'text')]
    private string $conteudo = '';

    #[ORM\Column(name: 'criada_em')]
    private \DateTimeImmutable $criadaEm;

    #[ORM\Column(name: 'editada_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $editadaEm = null;

    /**
     * O registro RAIZ que esta mensagem responde (desenho 1.2.3: "Resposta a X").
     *
     * A conversa tem UM nível só: responder a uma resposta aponta para a raiz dela
     * (quem garante é o `EnviarMensagemPastaUseCase`). `SET NULL` porque excluir a
     * original não apaga o que os outros responderam.
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'resposta_a_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?PastaMensagem $respostaA = null;

    /**
     * Marca que a mensagem NASCEU como resposta. Existe porque o `SET NULL` apaga o
     * vínculo quando a original é excluída: sem esta marca, a resposta órfã viraria
     * um registro comum e a tela não teria como dizer "Resposta a uma mensagem excluída".
     */
    #[ORM\Column(name: 'eh_resposta', type: 'boolean', options: ['default' => false])]
    private bool $ehResposta = false;

    public function __construct()
    {
        $this->criadaEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPasta(): ?Pasta
    {
        return $this->pasta;
    }

    public function setPasta(?Pasta $pasta): self
    {
        $this->pasta = $pasta;
        return $this;
    }

    public function getAutor(): ?User
    {
        return $this->autor;
    }

    public function setAutor(?User $autor): self
    {
        $this->autor = $autor;
        return $this;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(?Tenant $tenant): self
    {
        $this->tenant = $tenant;
        return $this;
    }

    public function getConteudo(): string
    {
        return $this->conteudo;
    }

    public function setConteudo(string $conteudo): self
    {
        $this->conteudo = $conteudo;
        return $this;
    }

    public function getCriadaEm(): \DateTimeImmutable
    {
        return $this->criadaEm;
    }

    public function getEditadaEm(): ?\DateTimeImmutable
    {
        return $this->editadaEm;
    }

    public function setEditadaEm(?\DateTimeImmutable $editadaEm): self
    {
        $this->editadaEm = $editadaEm;

        return $this;
    }

    public function getRespostaA(): ?PastaMensagem
    {
        return $this->respostaA;
    }

    /**
     * Liga a mensagem ao registro que ela responde. Uma vez resposta, sempre
     * resposta: desligar (`null`) não desfaz a marca `ehResposta`.
     */
    public function setRespostaA(?PastaMensagem $respostaA): self
    {
        $this->respostaA = $respostaA;
        if ($respostaA !== null) {
            $this->ehResposta = true;
        }

        return $this;
    }

    public function isResposta(): bool
    {
        return $this->ehResposta;
    }

    /** Resposta cuja original foi excluída (o vínculo caiu pelo `SET NULL`). */
    public function isRespostaOrfa(): bool
    {
        return $this->ehResposta && $this->respostaA === null;
    }

    /**
     * Indica se a mensagem pertence ao usuário informado (comparação por
     * instância e, como reforço, por id — útil quando há proxies do ORM).
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
