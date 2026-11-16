<?php

declare(strict_types=1);

namespace App\Contribua\Entity;

use App\Contribua\Enum\SituacaoDoPedidoDeEspaco;
use App\Contribua\Repository\PedidoDeEspacoRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * O "Solicitar mais espaço" de um escritório (desenho Contribua 14 e 15/11, `espaco_pedido`): a faixa de GB que ele
 * estima precisar e, se quiser, o motivo. O uso e o limite ficam gravados como estavam no momento do pedido, para o
 * Master decidir olhando o mesmo retrato que o escritório viu.
 *
 * Um pendente por escritório (regra do UseCase). O Master libera as opções de compra (grava a liberação no espaço do
 * escritório) ou recusa; quem decide e quando fica no pedido.
 *
 * Fora da auditoria, como a `Sugestao`: o Master decide o pedido de qualquer escritório e o `audit_log` gravaria a
 * decisão no escritório da SESSÃO dele.
 */
#[ORM\Entity(repositoryClass: PedidoDeEspacoRepository::class)]
#[ORM\Table(name: 'contribua_pedido_de_espaco')]
#[ORM\Index(name: 'idx_contribua_pedido_espaco_tenant_situacao', columns: ['tenant_id', 'situacao'])]
#[ORM\Index(name: 'idx_contribua_pedido_espaco_criado', columns: ['criado_em'])]
#[ORM\Index(name: 'idx_contribua_pedido_espaco_pedido_por', columns: ['pedido_por_id'])]
#[ORM\Index(name: 'idx_contribua_pedido_espaco_decidido_por', columns: ['decidido_por_id'])]
class PedidoDeEspaco implements TenantAware
{
    /** As faixas do desenho: os limites de Essencial, Profissional e Avançado (decisão D4). */
    public const FAIXAS_EM_GB = [20, 50, 100];
    public const TAMANHO_MAXIMO_DO_MOTIVO = 300;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 10, enumType: SituacaoDoPedidoDeEspaco::class)]
    private SituacaoDoPedidoDeEspaco $situacao = SituacaoDoPedidoDeEspaco::Pendente;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $decididoEm = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $decididoPor = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?User $pedidoPor,
        #[ORM\Column(type: 'smallint')]
        private int $gbDesejado,
        #[ORM\Column(length: 300, nullable: true)]
        private ?string $motivo,
        /** Uso medido no momento do pedido. */
        #[ORM\Column(type: 'bigint')]
        private int $usoBytes,
        /** Limite efetivo no momento do pedido; nulo quando o escritório estava sem limite. */
        #[ORM\Column(type: 'bigint', nullable: true)]
        private ?int $limiteBytes,
        ?\DateTimeImmutable $criadoEm = null,
    ) {
        if (!\in_array($gbDesejado, self::FAIXAS_EM_GB, true)) {
            throw new \DomainException('Escolha 20, 50 ou 100 GB.');
        }
        $motivo = $motivo === null ? '' : trim($motivo);
        if (mb_strlen($motivo) > self::TAMANHO_MAXIMO_DO_MOTIVO) {
            throw new \DomainException('O motivo pode ter até ' . self::TAMANHO_MAXIMO_DO_MOTIVO . ' caracteres.');
        }
        $this->motivo = $motivo === '' ? null : $motivo;
        $this->criadoEm = $criadoEm ?? new \DateTimeImmutable();
    }

    public function liberar(User $master, \DateTimeImmutable $em): void
    {
        $this->decidir(SituacaoDoPedidoDeEspaco::Liberado, $master, $em);
    }

    public function recusar(User $master, \DateTimeImmutable $em): void
    {
        $this->decidir(SituacaoDoPedidoDeEspaco::Recusado, $master, $em);
    }

    private function decidir(SituacaoDoPedidoDeEspaco $situacao, User $master, \DateTimeImmutable $em): void
    {
        if ($this->situacao !== SituacaoDoPedidoDeEspaco::Pendente) {
            throw new \DomainException('Este pedido já foi decidido.');
        }
        $this->situacao = $situacao;
        $this->decididoPor = $master;
        $this->decididoEm = $em;
    }

    public function estaPendente(): bool
    {
        return $this->situacao === SituacaoDoPedidoDeEspaco::Pendente;
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

    public function getGbDesejado(): int
    {
        return $this->gbDesejado;
    }

    public function getMotivo(): ?string
    {
        return $this->motivo;
    }

    public function getUsoBytes(): int
    {
        return $this->usoBytes;
    }

    public function getLimiteBytes(): ?int
    {
        return $this->limiteBytes;
    }

    public function getSituacao(): SituacaoDoPedidoDeEspaco
    {
        return $this->situacao;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getDecididoEm(): ?\DateTimeImmutable
    {
        return $this->decididoEm;
    }

    public function getDecididoPor(): ?User
    {
        return $this->decididoPor;
    }
}
