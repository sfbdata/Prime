<?php

declare(strict_types=1);

namespace App\Contribua\Entity;

use App\Contribua\Gateway\SituacaoDaAssinatura;
use App\Contribua\Gateway\SituacaoNoGateway;
use App\Contribua\Repository\AssinaturaDeEspacoRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um "Quero +N GB" no cartão, do pedido até o fim da assinatura (desenho 08, checkout e lista de pedidos
 * do Master). Nasce "aguardando confirmação" no clique; vira Ativa só quando o gateway confirma
 * (`consultar`, nunca o aviso sozinho) ou quando o Master confirma à mão; termina Cancelada ou
 * Inadimplente, valendo até o fim do mês pago (mais a carência, na inadimplência).
 *
 * Quem decide o plano do escritório a partir das assinaturas é o `PlanoVigenteDoEscritorio`.
 */
#[ORM\Entity(repositoryClass: AssinaturaDeEspacoRepository::class)]
#[ORM\Table(name: 'contribua_assinatura')]
#[ORM\UniqueConstraint(name: 'uniq_contribua_assinatura_gateway_id', columns: ['gateway', 'id_da_assinatura'])]
#[ORM\Index(name: 'idx_contribua_assinatura_tenant', columns: ['tenant_id', 'criada_em'])]
#[ORM\Index(name: 'idx_contribua_assinatura_situacao', columns: ['situacao'])]
#[ORM\Index(name: 'idx_contribua_assinatura_plano', columns: ['plano_id'])]
#[ORM\Index(name: 'idx_contribua_assinatura_pedida_por', columns: ['pedida_por_id'])]
#[ORM\Index(name: 'idx_contribua_assinatura_confirmada_por', columns: ['confirmada_por_id'])]
#[ORM\HasLifecycleCallbacks]
class AssinaturaDeEspaco implements TenantAware, Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: SituacaoDaAssinatura::class)]
    private SituacaoDaAssinatura $situacao = SituacaoDaAssinatura::Aguardando;

    /** Id do checkout/sessão no gateway, quando ele devolve na criação. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $idDoCheckout = null;

    /** A página hospedada do gateway, para reabrir o mesmo checkout em vez de criar outro. */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $urlDoCheckout = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $idDaAssinatura = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $idDoCliente = null;

    /** Fim do período pago. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $pagaAte = null;

    /** O pagador voltou pela página de retorno ("?assinatura=ok"): pagou, falta o gateway confirmar. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $retornouEm = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $ativadaEm = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $inadimplenteDesde = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $canceladaEm = null;

    /** O Master confirmou à mão ("Confirmar pagamento", sem webhook). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $confirmadaPor = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadaEm;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $atualizadaEm = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: Plano::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Plano $plano,
        /** `GatewayDeAssinatura::codigo()` de quando o pedido foi feito. */
        #[ORM\Column(length: 20)]
        private string $gateway,
        /** Valor mensal no pedido, em centavos (o preço do plano pode mudar depois). */
        #[ORM\Column(type: 'integer')]
        private int $valorCentavos,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?User $pedidaPor,
    ) {
        $this->criadaEm = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function aoAtualizar(): void
    {
        $this->atualizadaEm = new \DateTimeImmutable();
    }

    /** `BJ<conta>-<plano>-<id>`: a referência que vai ao gateway e volta nos avisos. */
    public function referencia(): string
    {
        if ($this->id === null) {
            throw new \LogicException('A referência só existe depois de gravar o pedido.');
        }

        return sprintf('BJ%06d-%s-%d', (int) $this->tenant->getId(), $this->plano->getCodigo(), $this->id);
    }

    public function registrarInicio(?string $idDoCheckout, ?string $urlDoCheckout = null): void
    {
        $this->idDoCheckout = $idDoCheckout;
        $this->urlDoCheckout = $urlDoCheckout;
    }

    public function getUrlDoCheckout(): ?string
    {
        return $this->urlDoCheckout;
    }

    /** Ainda não foi ao gateway de verdade: nem assinatura ligada, nem pagamento. */
    public function semNadaNoGateway(): bool
    {
        return $this->situacao === SituacaoDaAssinatura::Aguardando && $this->idDaAssinatura === null;
    }

    public function vincularNoGateway(string $idDaAssinatura, ?string $idDoCliente): void
    {
        if ($this->idDaAssinatura !== null && $this->idDaAssinatura !== $idDaAssinatura) {
            throw new \DomainException('Este pedido já está ligado a outra assinatura no gateway.');
        }

        $this->idDaAssinatura = $idDaAssinatura;
        $this->idDoCliente = $idDoCliente ?? $this->idDoCliente;
    }

    public function marcarRetorno(): void
    {
        $this->retornouEm ??= new \DateTimeImmutable();
    }

    /**
     * Aplica o que o gateway respondeu. Devolve true quando a situação mudou (quem chama avisa o primário).
     *
     * Uma confirmação manual do Master não é desfeita por um gateway que ainda diz "aguardando": quem
     * já pagou não perde o espaço porque a cobrança demorou a compensar. Cancelada não volta.
     */
    public function aplicar(SituacaoNoGateway $noGateway): bool
    {
        $anterior = $this->situacao;
        if ($noGateway->situacao === SituacaoDaAssinatura::Aguardando && $anterior !== SituacaoDaAssinatura::Aguardando) {
            return false;
        }
        // Cancelada é final: o gateway não reativa uma assinatura apagada, e uma reconsulta lida antes do
        // cancelamento e aplicada depois não pode ressuscitá-la (nem avisar "pagamento confirmado").
        if ($anterior === SituacaoDaAssinatura::Cancelada) {
            return false;
        }

        $this->pagaAte = self::maisTarde($this->pagaAte, $noGateway->pagaAte);
        $this->mudarPara($noGateway->situacao);

        return $anterior !== $this->situacao;
    }

    /** "Confirmar pagamento" do Master: vale um mês a partir de agora. */
    public function confirmarManualmente(User $master, \DateTimeImmutable $agora): void
    {
        if ($this->situacao !== SituacaoDaAssinatura::Aguardando) {
            throw new \DomainException('Só um pedido aguardando confirmação pode ser confirmado.');
        }

        $this->confirmadaPor = $master;
        $this->pagaAte = $agora->modify('+1 month');
        $this->mudarPara(SituacaoDaAssinatura::Ativa);
    }

    public function cancelar(): void
    {
        $this->mudarPara(SituacaoDaAssinatura::Cancelada);
    }

    /**
     * Ainda dá direito ao espaço do plano? Ativa e inadimplente valem até o fim do período pago mais a
     * carência (a renovação pode chegar com atraso); cancelada vale só até o fim do período pago.
     */
    public function vigenteEm(\DateTimeImmutable $agora, int $diasDeCarencia): bool
    {
        if ($this->pagaAte === null) {
            return false;
        }

        return match ($this->situacao) {
            SituacaoDaAssinatura::Aguardando => false,
            SituacaoDaAssinatura::Cancelada => $agora <= $this->pagaAte,
            SituacaoDaAssinatura::Ativa, SituacaoDaAssinatura::Inadimplente => $agora <= $this->pagaAte->modify(sprintf('+%d days', $diasDeCarencia)),
        };
    }

    private function mudarPara(SituacaoDaAssinatura $nova): void
    {
        if ($nova === $this->situacao) {
            return;
        }

        $agora = new \DateTimeImmutable();
        match ($nova) {
            SituacaoDaAssinatura::Ativa => $this->ativadaEm ??= $agora,
            SituacaoDaAssinatura::Inadimplente => $this->inadimplenteDesde = $agora,
            SituacaoDaAssinatura::Cancelada => $this->canceladaEm = $agora,
            SituacaoDaAssinatura::Aguardando => null,
        };
        if ($nova === SituacaoDaAssinatura::Ativa) {
            $this->inadimplenteDesde = null;
        }
        $this->situacao = $nova;
    }

    private static function maisTarde(?\DateTimeImmutable $a, ?\DateTimeImmutable $b): ?\DateTimeImmutable
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return $a > $b ? $a : $b;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getPlano(): Plano
    {
        return $this->plano;
    }

    public function getGateway(): string
    {
        return $this->gateway;
    }

    public function getValorCentavos(): int
    {
        return $this->valorCentavos;
    }

    public function getSituacao(): SituacaoDaAssinatura
    {
        return $this->situacao;
    }

    public function getIdDoCheckout(): ?string
    {
        return $this->idDoCheckout;
    }

    public function getIdDaAssinatura(): ?string
    {
        return $this->idDaAssinatura;
    }

    public function getIdDoCliente(): ?string
    {
        return $this->idDoCliente;
    }

    public function getPagaAte(): ?\DateTimeImmutable
    {
        return $this->pagaAte;
    }

    public function getRetornouEm(): ?\DateTimeImmutable
    {
        return $this->retornouEm;
    }

    public function getInadimplenteDesde(): ?\DateTimeImmutable
    {
        return $this->inadimplenteDesde;
    }

    public function getCanceladaEm(): ?\DateTimeImmutable
    {
        return $this->canceladaEm;
    }

    public function getConfirmadaPor(): ?User
    {
        return $this->confirmadaPor;
    }

    public function getPedidaPor(): ?User
    {
        return $this->pedidaPor;
    }

    public function getCriadaEm(): \DateTimeImmutable
    {
        return $this->criadaEm;
    }
}
