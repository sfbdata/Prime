<?php

declare(strict_types=1);

namespace App\Tenant\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use App\Tenant\Repository\CodigoDeExclusaoDeContaRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Código de 6 dígitos da etapa 2 da exclusão da conta (PR 5, decisão E4), enviado ao e-mail do primário.
 * Só o hash fica gravado (HMAC de `tenantId:userId:codigo` com o kernel.secret): vale só para o par
 * usuário + escritório. 15 minutos, 5 erros queimam (contados por UPDATE atômico no repositório) e o
 * consumo é atômico. Não é Auditavel: o rastro é do pedido; o código não diz nada a ninguém.
 */
#[ORM\Entity(repositoryClass: CodigoDeExclusaoDeContaRepository::class)]
#[ORM\Table(name: 'codigo_exclusao_conta')]
#[ORM\Index(name: 'idx_codigo_exclusao_conta_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_codigo_exclusao_conta_par', columns: ['tenant_id', 'usuario_id'])]
#[ORM\Index(name: 'idx_codigo_exclusao_conta_usuario', columns: ['usuario_id'])]
class CodigoDeExclusaoDeConta implements TenantAware
{
    public const MAX_TENTATIVAS = 5;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $tentativas = 0;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $usadoEm = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $usuario,
        #[ORM\Column(length: 64)]
        private string $tokenHash,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $criadoEm,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $expiraEm,
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

    public function getUsuario(): User
    {
        return $this->usuario;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getExpiraEm(): \DateTimeImmutable
    {
        return $this->expiraEm;
    }

    public function getTentativas(): int
    {
        return $this->tentativas;
    }

    public function getUsadoEm(): ?\DateTimeImmutable
    {
        return $this->usadoEm;
    }
}
