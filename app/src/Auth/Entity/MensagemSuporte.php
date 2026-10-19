<?php

declare(strict_types=1);

namespace App\Auth\Entity;

use App\Auth\Repository\MensagemSuporteRepository;
use App\Entity\Auth\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mensagem do "Falar com suporte" da tela de entrada (desenho 10). Quem escreve não está logado: nome e e-mail são o
 * que a pessoa digitou, sem garantia nenhuma. `destinatario` é quem o Master escolheu no momento do envio (nulo =
 * todos os Masters).
 *
 * NÃO é multi-tenant: chega antes de qualquer escritório.
 */
#[ORM\Entity(repositoryClass: MensagemSuporteRepository::class)]
#[ORM\Table(name: 'mensagem_suporte')]
#[ORM\Index(name: 'idx_mensagem_suporte_criada_em', columns: ['criada_em'])]
class MensagemSuporte
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(length: 120)]
        private string $nome,
        #[ORM\Column(length: 180)]
        private string $email,
        #[ORM\Column(length: 40, nullable: true)]
        private ?string $assunto,
        #[ORM\Column(type: 'text')]
        private string $mensagem,
        #[ORM\Column(length: 45)]
        private string $ip,
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $userAgent = null,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?User $destinatario = null,
        #[ORM\Column(length: 20)]
        private string $origem = 'login',
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $criadaEm = new \DateTimeImmutable(),
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNome(): string
    {
        return $this->nome;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getAssunto(): ?string
    {
        return $this->assunto;
    }

    public function getMensagem(): string
    {
        return $this->mensagem;
    }

    public function getIp(): string
    {
        return $this->ip;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getDestinatario(): ?User
    {
        return $this->destinatario;
    }

    public function getOrigem(): string
    {
        return $this->origem;
    }

    public function getCriadaEm(): \DateTimeImmutable
    {
        return $this->criadaEm;
    }
}
