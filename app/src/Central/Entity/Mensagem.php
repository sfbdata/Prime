<?php

declare(strict_types=1);

namespace App\Central\Entity;

use App\Central\Repository\MensagemRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mensagem de texto numa conversa da Central (A1). Texto puro: a tela escapa (Twig) ou usa `textContent`
 * (JS) — nunca HTML. O id é o cursor do polling e da leitura, por isso o índice `(conversa_id, id)`.
 *
 * Fora da auditoria de propósito, como a conversa (ver `Conversa`).
 *
 * A2: pode responder a outra mensagem — sempre da MESMA conversa (invariante do construtor). Apagar a original
 * não apaga a resposta: a citação some (`ON DELETE SET NULL`).
 */
#[ORM\Entity(repositoryClass: MensagemRepository::class)]
#[ORM\Table(name: 'central_mensagem')]
#[ORM\Index(name: 'idx_central_mensagem_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_central_mensagem_conversa_id', columns: ['conversa_id', 'id'])]
class Mensagem implements TenantAware
{
    public const TAMANHO_MAXIMO = 4000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'text')]
    private string $conteudo;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: Conversa::class)]
        #[ORM\JoinColumn(name: 'conversa_id', nullable: false, onDelete: 'CASCADE')]
        private Conversa $conversa,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'autor_id', nullable: false)]
        private User $autor,
        string $conteudo,
        #[ORM\Column(name: 'criada_em', type: 'datetime_immutable')]
        private \DateTimeImmutable $criadaEm,
        #[ORM\ManyToOne(targetEntity: self::class)]
        #[ORM\JoinColumn(name: 'resposta_a_id', nullable: true, onDelete: 'SET NULL')]
        private ?Mensagem $respostaA = null,
    ) {
        if ($respostaA !== null && $respostaA->getConversa() !== $conversa) {
            throw new \DomainException('A mensagem respondida não é desta conversa.');
        }
        $conteudo = trim($conteudo);
        if ($conteudo === '') {
            throw new \DomainException('A mensagem está vazia.');
        }
        if (mb_strlen($conteudo) > self::TAMANHO_MAXIMO) {
            throw new \DomainException(sprintf('A mensagem passa de %d caracteres.', self::TAMANHO_MAXIMO));
        }

        $this->conteudo = $conteudo;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getConversa(): Conversa
    {
        return $this->conversa;
    }

    public function getAutor(): User
    {
        return $this->autor;
    }

    public function getConteudo(): string
    {
        return $this->conteudo;
    }

    public function getCriadaEm(): \DateTimeImmutable
    {
        return $this->criadaEm;
    }

    public function getRespostaA(): ?Mensagem
    {
        return $this->respostaA;
    }
}
