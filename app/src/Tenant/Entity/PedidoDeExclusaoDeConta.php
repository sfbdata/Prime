<?php

declare(strict_types=1);

namespace App\Tenant\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use App\Tenant\Enum\EstadoDoPedidoDeExclusao;
use App\Tenant\Repository\PedidoDeExclusaoDeContaRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Pedido de exclusão da conta feito por um primário (PR 5, docs/specs/conta-pedido-exclusao.md). Enquanto
 * "pendente", a conta fica "suspensa a pedido": o Tenant continua ativo, e a barreira (b) do
 * TenantContextValidatorListener segura a equipe (só primários entram, no Meu Perfil). Quem decide é o
 * Master (painel, frente b5): reativar ou excluir (quarentena de 365 dias a partir da decisão).
 *
 * Um pendente por conta: índice único parcial na migration (`WHERE estado = 'pendente'`).
 */
#[ORM\Entity(repositoryClass: PedidoDeExclusaoDeContaRepository::class)]
#[ORM\Table(name: 'pedido_exclusao_conta')]
#[ORM\Index(name: 'idx_pedido_exclusao_conta_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_pedido_exclusao_conta_pedido_por', columns: ['pedido_por_id'])]
#[ORM\Index(name: 'idx_pedido_exclusao_conta_decidido_por', columns: ['decidido_por_id'])]
class PedidoDeExclusaoDeConta implements TenantAware, Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: EstadoDoPedidoDeExclusao::class)]
    private EstadoDoPedidoDeExclusao $estado = EstadoDoPedidoDeExclusao::Pendente;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $decididoPor = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $decididoEm = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?User $pedidoPor,
        #[ORM\Column(length: 600)]
        private string $motivo,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $criadoEm,
    ) {
    }

    /** O primário desistiu: a equipe volta a entrar. */
    public function desistir(User $ator, \DateTimeImmutable $em = new \DateTimeImmutable()): void
    {
        $this->decidir(EstadoDoPedidoDeExclusao::Desistida, $ator, $em);
    }

    /** O Master reativou pelo painel (ReativarContaUseCase). */
    public function marcarReativada(User $ator, \DateTimeImmutable $em = new \DateTimeImmutable()): void
    {
        $this->decidir(EstadoDoPedidoDeExclusao::Reativada, $ator, $em);
    }

    /** O Master excluiu (quarentena): ExcluirContaPeloMasterUseCase ou a rota antiga do Master. */
    public function marcarExcluida(User $ator, \DateTimeImmutable $em = new \DateTimeImmutable()): void
    {
        $this->decidir(EstadoDoPedidoDeExclusao::Excluida, $ator, $em);
    }

    private function decidir(EstadoDoPedidoDeExclusao $estado, User $ator, \DateTimeImmutable $em): void
    {
        if ($this->estado !== EstadoDoPedidoDeExclusao::Pendente) {
            throw new \DomainException('Este pedido de exclusão já foi decidido.');
        }

        $this->estado = $estado;
        $this->decididoPor = $ator;
        $this->decididoEm = $em;
    }

    public function estaPendente(): bool
    {
        return $this->estado === EstadoDoPedidoDeExclusao::Pendente;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getPedidoPor(): ?User
    {
        return $this->pedidoPor;
    }

    public function getMotivo(): string
    {
        return $this->motivo;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getEstado(): EstadoDoPedidoDeExclusao
    {
        return $this->estado;
    }

    public function getDecididoPor(): ?User
    {
        return $this->decididoPor;
    }

    public function getDecididoEm(): ?\DateTimeImmutable
    {
        return $this->decididoEm;
    }
}
