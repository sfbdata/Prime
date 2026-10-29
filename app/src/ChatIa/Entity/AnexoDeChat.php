<?php

declare(strict_types=1);

namespace App\ChatIa\Entity;

use App\ChatIa\Repository\AnexoDeChatRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um arquivo anexado a uma pergunta do Chat I.A (PDF, imagem ou DOCX). Nasce SOLTO (`mensagem` nula) no envio e é
 * amarrado à pergunta quando ela é gravada. Só quem o enviou o usa, e só na conversa para a qual foi enviado. O
 * conteúdo está no armazenamento (`chave` = o nome cunhado pelo storage); `mime` é o tipo medido pelo CONTEÚDO.
 */
#[ORM\Entity(repositoryClass: AnexoDeChatRepository::class)]
#[ORM\Table(name: 'chat_ia_anexo')]
#[ORM\Index(name: 'idx_chat_ia_anexo_conversa', columns: ['conversa_id'])]
#[ORM\Index(name: 'idx_chat_ia_anexo_mensagem', columns: ['mensagem_id'])]
class AnexoDeChat implements TenantAware, Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MensagemDeChat::class)]
    #[ORM\JoinColumn(name: 'mensagem_id', nullable: true, onDelete: 'CASCADE')]
    private ?MensagemDeChat $mensagem = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'usuario_id', nullable: false)]
        private User $usuario,
        #[ORM\ManyToOne(targetEntity: ConversaDeChat::class)]
        #[ORM\JoinColumn(name: 'conversa_id', nullable: false, onDelete: 'CASCADE')]
        private ConversaDeChat $conversa,
        #[ORM\Column(length: 255)]
        private string $chave,
        #[ORM\Column(length: 255)]
        private string $nome,
        #[ORM\Column(length: 100)]
        private string $mime,
        #[ORM\Column(type: 'integer')]
        private int $tamanho,
        #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
        private \DateTimeImmutable $criadoEm,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function getUsuario(): User
    {
        return $this->usuario;
    }

    public function getConversa(): ConversaDeChat
    {
        return $this->conversa;
    }

    public function getMensagem(): ?MensagemDeChat
    {
        return $this->mensagem;
    }

    public function getChave(): string
    {
        return $this->chave;
    }

    public function getNome(): string
    {
        return $this->nome;
    }

    public function getMime(): string
    {
        return $this->mime;
    }

    public function getTamanho(): int
    {
        return $this->tamanho;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function anexarA(MensagemDeChat $mensagem): void
    {
        $this->mensagem = $mensagem;
    }

    public function estaSolto(): bool
    {
        return $this->mensagem === null;
    }
}
