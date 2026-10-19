<?php

declare(strict_types=1);

namespace App\Shared\Email\Descadastro\Entity;

use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use App\Shared\Email\Descadastro\Repository\EmailDescadastroRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um endereço que pediu para não receber mais um ASSUNTO de e-mail de um escritório (spec
 * push-avisar-quando-chegar D3). Reaproveitável: o assunto é texto livre validado
 * (`push_aviso_equipe` hoje; campanhas e e-mails comerciais depois), não um enum preso ao Push.
 *
 * Anatomia:
 *   - UNIQUE (tenant_id, email, assunto): árbitro do INSERT idempotente (`ON CONFLICT DO NOTHING`);
 *   - `email` normalizado (minúsculo, sem espaços nas pontas);
 *   - `origem` diz por onde veio (`link_do_email`, `one_click`), para prova.
 *
 * Fora da auditoria (AuditavelCoberturaTest): a própria linha é o registro do pedido (quem, quando,
 * de onde), gravada sem sessão (quem clica no link não está logado) e nunca editada.
 * Gravada e lida por SQL ({@see EmailDescadastroRepository}): a entidade existe para o esquema e a purga.
 */
#[ORM\Entity(repositoryClass: EmailDescadastroRepository::class)]
#[ORM\Table(name: 'email_descadastro')]
#[ORM\Index(name: 'idx_email_descadastro_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_email_descadastro_tenant_email_assunto', columns: ['tenant_id', 'email', 'assunto'])]
class EmailDescadastro implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
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

    public function getTenant(): Tenant
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
}
