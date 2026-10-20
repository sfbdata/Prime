<?php

declare(strict_types=1);

namespace App\Comercial\Entity;

use App\Entity\Tenant\Tenant;
use Doctrine\ORM\Mapping as ORM;

/**
 * O estado atual da confirmação de um endereço numa lista (spec §3.3). A prova é {@see FatoDeConsentimento}. Um
 * índice parcial por escopo, como o descadastro da 0d (o NULL não colide em UNIQUE comum).
 */
#[ORM\Entity]
#[ORM\Table(name: 'comercial_confirmacao_endereco')]
#[ORM\UniqueConstraint(name: 'uniq_comercial_confirmacao_escritorio', columns: ['tenant_id', 'lista', 'email'], options: ['where' => '(tenant_id IS NOT NULL)'])]
#[ORM\UniqueConstraint(name: 'uniq_comercial_confirmacao_plataforma', columns: ['lista', 'email'], options: ['where' => '(tenant_id IS NULL)'])]
#[ORM\UniqueConstraint(name: 'uniq_comercial_confirmacao_token', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_comercial_confirmacao_tenant', columns: ['tenant_id'])]
class ConfirmacaoDeEndereco
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', nullable: true)]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 40)]
    private string $lista = '';

    #[ORM\Column(length: 180)]
    private string $email = '';

    #[ORM\Column(name: 'token_hash', length: 64, nullable: true)]
    private ?string $tokenHash = null;

    #[ORM\Column(name: 'pedido_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $pedidoEm;

    #[ORM\Column(name: 'expira_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiraEm = null;

    #[ORM\Column(name: 'confirmado_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $confirmadoEm = null;

    public function __construct()
    {
        $this->pedidoEm = new \DateTimeImmutable();
    }
}
