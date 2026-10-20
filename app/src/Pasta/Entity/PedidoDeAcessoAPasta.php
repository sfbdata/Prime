<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PedidoDeAcessoAPastaRepository;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Pedido de acesso a uma pasta com cadeado (spec pasta-cadeado K5, desenho L1282-1290 e
 * `bluejus-central.js` `pedirAcessoPasta`/`cadResolver`).
 *
 * Fica um pendente por pasta e pessoa: pedir de novo dentro de 24 h é recusado; depois de 24 h, o mesmo
 * pedido pendente é renovado (nova hora, novo nível e motivo) em vez de abrir outro. Recusado também segura
 * um pedido novo por 24 h desde o anterior (antispam); liberado, não.
 *
 * Anatomia: índice único PARCIAL (pasta_id, solicitante_id) WHERE estado = 'pendente', a trava do "um
 * pendente" no banco; pasta e solicitante CASCADE; quem decidiu SET NULL.
 */
#[ORM\Entity(repositoryClass: PedidoDeAcessoAPastaRepository::class)]
#[ORM\Table(name: 'pasta_pedido_acesso')]
#[ORM\Index(name: 'idx_pasta_pedido_acesso_pasta_solicitante', columns: ['pasta_id', 'solicitante_id'])]
#[ORM\Index(name: 'idx_pasta_pedido_acesso_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_pasta_pedido_acesso_pendente', columns: ['pasta_id', 'solicitante_id'], options: ['where' => "((estado)::text = 'pendente'::text)"])]
class PedidoDeAcessoAPasta implements Auditavel, TenantAware
{
    public const MOTIVO_MAXIMO = 300;

    /** A janela do desenho (`864e5` ms): um pedido pendente bloqueia outro por 24 horas. */
    public const JANELA_EM_HORAS = 24;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'nivel_pedido', length: 20, enumType: NivelDeAcesso::class)]
    private NivelDeAcesso $nivelPedido;

    #[ORM\Column(length: 300, nullable: true)]
    private ?string $motivo;

    #[ORM\Column(length: 30, enumType: EstadoDoPedidoDeAcesso::class)]
    private EstadoDoPedidoDeAcesso $estado = EstadoDoPedidoDeAcesso::Pendente;

    #[ORM\Column(name: 'pedido_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $pedidoEm;

    #[ORM\Column(name: 'decidido_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $decididoEm = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'decidido_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $decididoPor = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: Pasta::class)]
        #[ORM\JoinColumn(name: 'pasta_id', nullable: false, onDelete: 'CASCADE')]
        private Pasta $pasta,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'solicitante_id', nullable: false, onDelete: 'CASCADE')]
        private User $solicitante,
        NivelDeAcesso $nivelPedido,
        ?string $motivo,
        \DateTimeImmutable $em,
    ) {
        $this->preencher($nivelPedido, $motivo, $em);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getPasta(): Pasta
    {
        return $this->pasta;
    }

    public function getSolicitante(): User
    {
        return $this->solicitante;
    }

    public function getNivelPedido(): NivelDeAcesso
    {
        return $this->nivelPedido;
    }

    public function getMotivo(): ?string
    {
        return $this->motivo;
    }

    public function getEstado(): EstadoDoPedidoDeAcesso
    {
        return $this->estado;
    }

    public function getPedidoEm(): \DateTimeImmutable
    {
        return $this->pedidoEm;
    }

    public function getDecididoEm(): ?\DateTimeImmutable
    {
        return $this->decididoEm;
    }

    public function getDecididoPor(): ?User
    {
        return $this->decididoPor;
    }

    public function estaPendente(): bool
    {
        return $this->estado === EstadoDoPedidoDeAcesso::Pendente;
    }

    public function foiRecusado(): bool
    {
        return $this->estado === EstadoDoPedidoDeAcesso::Recusado;
    }

    /** Pendente e ainda dentro das 24 h: é o "Pedido enviado às HH:MM. Aguardando o usuário primário." */
    public function aguardandoEm(\DateTimeImmutable $agora): bool
    {
        return $this->estaPendente() && $agora < $this->fimDaJanela();
    }

    /**
     * Até quando este pedido segura um novo da mesma pessoa nesta pasta (antispam): 24 h desde ele, se está
     * pendente ou foi recusado. `null` = não segura (liberado, ou a janela já passou).
     */
    public function seguraNovoPedidoAte(\DateTimeImmutable $agora): ?\DateTimeImmutable
    {
        if (!$this->estaPendente() && !$this->foiRecusado()) {
            return null;
        }
        $fim = $this->fimDaJanela();

        return $agora < $fim ? $fim : null;
    }

    /** Pedido pendente com mais de 24 h, pedido de novo: renova o mesmo registro (um pendente por pessoa). */
    public function renovar(NivelDeAcesso $nivelPedido, ?string $motivo, \DateTimeImmutable $em): void
    {
        if ($this->aguardandoEm($em)) {
            throw new \DomainException('Você já pediu acesso a esta pasta hoje. Aguarde o usuário primário.');
        }
        if (!$this->estaPendente()) {
            throw new \LogicException('Só o pedido pendente é renovado; o decidido dá lugar a um novo.');
        }
        $this->preencher($nivelPedido, $motivo, $em);
    }

    public function liberar(NivelDeAcesso $nivel, User $por, \DateTimeImmutable $em): void
    {
        $this->decidir(EstadoDoPedidoDeAcesso::liberadoPara($nivel), $por, $em);
    }

    public function recusar(User $por, \DateTimeImmutable $em): void
    {
        $this->decidir(EstadoDoPedidoDeAcesso::Recusado, $por, $em);
    }

    private function decidir(EstadoDoPedidoDeAcesso $estado, User $por, \DateTimeImmutable $em): void
    {
        if (!$this->estaPendente()) {
            throw new \DomainException($this->foiRecusado() ? 'Este pedido já foi recusado.' : 'Este pedido já foi atendido.');
        }
        $this->estado      = $estado;
        $this->decididoPor = $por;
        $this->decididoEm  = $em;
    }

    private function preencher(NivelDeAcesso $nivelPedido, ?string $motivo, \DateTimeImmutable $em): void
    {
        if (!$nivelPedido->podeVer()) {
            throw new \InvalidArgumentException('O pedido é para visualizar ou para editar.');
        }
        $motivo = $motivo !== null ? trim($motivo) : '';
        if (mb_strlen($motivo) > self::MOTIVO_MAXIMO) {
            throw new \DomainException(sprintf('O motivo tem no máximo %d caracteres.', self::MOTIVO_MAXIMO));
        }
        $this->nivelPedido = $nivelPedido;
        $this->motivo      = $motivo === '' ? null : $motivo;
        $this->pedidoEm    = $em;
    }

    private function fimDaJanela(): \DateTimeImmutable
    {
        return $this->pedidoEm->modify(sprintf('+%d hours', self::JANELA_EM_HORAS));
    }
}
