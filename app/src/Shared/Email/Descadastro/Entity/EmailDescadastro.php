<?php

declare(strict_types=1);

namespace App\Shared\Email\Descadastro\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use App\Shared\Email\Descadastro\Repository\EmailDescadastroRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um pedido de um endereço para não receber mais um ASSUNTO de e-mail de um REMETENTE (spec
 * push-avisar-quando-chegar D3). Reaproveitável: o assunto é texto livre validado
 * (`push_aviso_equipe` hoje; campanhas e e-mails comerciais depois), não um enum preso ao Push, e
 * `todos` é o opt-out total daquele remetente.
 *
 * Escopo do remetente: `tenant_id` preenchido = o escritório; NULL = a PLATAFORMA (Master, Central,
 * alertas de vagas). Os escopos não se misturam (ver {@see \App\Shared\Email\Descadastro\DescadastroDeEmailInterface}).
 *
 * Histórico, nada se apaga: a linha está ATIVA enquanto `reativado_em` é NULL. Reativar encerra a
 * linha (`reativado_em`, `reativado_origem`, `reativado_por_id`); um novo descadastro depois disso é
 * uma linha nova. A "exceção" (liberar um assunto com `todos` ativo) é uma linha que já nasce
 * reativada.
 *
 * Anatomia:
 *   - unicidade só entre as linhas ATIVAS de cada escopo, em dois índices PARCIAIS (o NULL do Postgres
 *     não colide em UNIQUE comum): (tenant_id, email, assunto) onde tenant_id IS NOT NULL e ativa, e
 *     (email, assunto) onde tenant_id IS NULL e ativa. São os árbitros do INSERT idempotente;
 *   - `email` normalizado (minúsculo, sem espaços nas pontas);
 *   - `origem` diz por onde veio (`link_do_email`, `one_click`, `manual_master`...) e `motivo`, o
 *     porquê, quando alguém o informou (até 280 caracteres).
 *
 * Fora da auditoria (AuditavelCoberturaTest): a própria linha é o registro do pedido e da reativação
 * (quem, quando, de onde), gravada por SQL, quase sempre sem sessão.
 * Gravada e lida por SQL ({@see EmailDescadastroRepository}): a entidade existe para o esquema e a purga.
 */
#[ORM\Entity(repositoryClass: EmailDescadastroRepository::class)]
#[ORM\Table(name: 'email_descadastro')]
#[ORM\Index(name: 'idx_email_descadastro_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_email_descadastro_escritorio', columns: ['tenant_id', 'email', 'assunto'], options: ['where' => '((tenant_id IS NOT NULL) AND (reativado_em IS NULL))'])]
#[ORM\UniqueConstraint(name: 'uniq_email_descadastro_plataforma', columns: ['email', 'assunto'], options: ['where' => '((tenant_id IS NULL) AND (reativado_em IS NULL))'])]
class EmailDescadastro implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 280, nullable: true)]
    private ?string $motivo = null;

    #[ORM\Column(name: 'reativado_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $reativadoEm = null;

    #[ORM\Column(name: 'reativado_origem', length: 40, nullable: true)]
    private ?string $reativadoOrigem = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'reativado_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $reativadoPor = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: true)]
        private ?Tenant $tenant,
        #[ORM\Column(length: 180)]
        private string $email,
        #[ORM\Column(length: 40)]
        private string $assunto,
        #[ORM\Column(length: 40)]
        private string $origem,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $em,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /** Null = descadastro da plataforma. */
    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getAssunto(): string
    {
        return $this->assunto;
    }

    public function getOrigem(): string
    {
        return $this->origem;
    }

    public function getEm(): \DateTimeImmutable
    {
        return $this->em;
    }

    public function getMotivo(): ?string
    {
        return $this->motivo;
    }

    public function getReativadoEm(): ?\DateTimeImmutable
    {
        return $this->reativadoEm;
    }

    public function getReativadoOrigem(): ?string
    {
        return $this->reativadoOrigem;
    }

    public function getReativadoPor(): ?User
    {
        return $this->reativadoPor;
    }
}
