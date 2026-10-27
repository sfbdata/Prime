<?php

declare(strict_types=1);

namespace App\ServiceDesk\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\ServiceDesk\Enum\PrioridadeDoChamado;
use App\ServiceDesk\Enum\StatusDoChamado;
use App\ServiceDesk\Enum\TipoDeChamado;
use App\ServiceDesk\Repository\ChamadoDeSuporteRepository;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Pedido do escritório à equipe técnica do BlueJus (desenho 12). Abrir NÃO debita: guarda o valor
 * estimado (preço configurado pelo Master, ou nulo se não houver preço). O débito é da equipe, ao
 * concluir o serviço. Número sequencial por escritório (#0001), reservado com o saldo travado.
 */
#[ORM\Entity(repositoryClass: ChamadoDeSuporteRepository::class)]
#[ORM\Table(name: 'service_desk_chamado')]
#[ORM\UniqueConstraint(name: 'uniq_service_desk_chamado_numero', columns: ['tenant_id', 'numero'])]
#[ORM\Index(name: 'idx_service_desk_chamado_tenant_status', columns: ['tenant_id', 'status'])]
class ChamadoDeSuporte implements TenantAware, Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(enumType: StatusDoChamado::class, length: 20)]
    private StatusDoChamado $status = StatusDoChamado::Aberto;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $valorDebitadoCentavos = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    private function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Tenant $tenant,
        #[ORM\Column(type: 'integer')]
        private int $numero,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?User $solicitante,
        #[ORM\Column(enumType: TipoDeChamado::class, length: 20)]
        private TipoDeChamado $tipo,
        #[ORM\Column(enumType: PrioridadeDoChamado::class, length: 20)]
        private PrioridadeDoChamado $prioridade,
        #[ORM\Column(length: 255)]
        private string $assunto,
        #[ORM\Column(type: 'text')]
        private string $descricao,
        #[ORM\Column(type: 'date_immutable', nullable: true)]
        private ?\DateTimeImmutable $dataAgendada,
        #[ORM\Column(length: 5, nullable: true)]
        private ?string $horaAgendada,
        #[ORM\Column(type: 'integer', nullable: true)]
        private ?int $valorEstimadoCentavos,
    ) {
        $this->criadoEm = new \DateTimeImmutable();
    }

    public static function abrir(
        Tenant $tenant,
        int $numero,
        ?User $solicitante,
        TipoDeChamado $tipo,
        PrioridadeDoChamado $prioridade,
        string $assunto,
        string $descricao,
        ?\DateTimeImmutable $dataAgendada,
        ?string $horaAgendada,
        ?int $valorEstimadoCentavos,
    ): self {
        self::exigirPedidoValido($tipo, $assunto, $dataAgendada, $horaAgendada);
        $assunto = trim($assunto);
        if ($tipo->exigeAgendamento()) {
            $dataAgendada = $dataAgendada?->setTime(0, 0);
        } else {
            $dataAgendada = null;
            $horaAgendada = null;
        }

        return new self($tenant, $numero, $solicitante, $tipo, $prioridade, mb_substr($assunto, 0, 255), trim($descricao), $dataAgendada, $horaAgendada, $valorEstimadoCentavos);
    }

    /** Regras do formulário "Abrir chamado". Pública para o UseCase validar ANTES de abrir a transação. */
    public static function exigirPedidoValido(TipoDeChamado $tipo, string $assunto, ?\DateTimeImmutable $data, ?string $hora): void
    {
        if ('' === trim($assunto)) {
            throw new \DomainException('Informe o assunto.');
        }
        if ($tipo->exigeAgendamento() && (null === $data || null === $hora || '' === $hora)) {
            throw new \DomainException('Escolha a data e o horário do agendamento.');
        }
        if ($tipo->exigeAgendamento() && 1 !== preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $hora)) {
            throw new \DomainException('Informe o horário no formato HH:MM.');
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getNumero(): int
    {
        return $this->numero;
    }

    public function getSolicitante(): ?User
    {
        return $this->solicitante;
    }

    public function getTipo(): TipoDeChamado
    {
        return $this->tipo;
    }

    public function getPrioridade(): PrioridadeDoChamado
    {
        return $this->prioridade;
    }

    public function getAssunto(): string
    {
        return $this->assunto;
    }

    public function getDescricao(): string
    {
        return $this->descricao;
    }

    public function getDataAgendada(): ?\DateTimeImmutable
    {
        return $this->dataAgendada;
    }

    public function getHoraAgendada(): ?string
    {
        return $this->horaAgendada;
    }

    public function getStatus(): StatusDoChamado
    {
        return $this->status;
    }

    public function getValorEstimadoCentavos(): ?int
    {
        return $this->valorEstimadoCentavos;
    }

    public function getValorDebitadoCentavos(): ?int
    {
        return $this->valorDebitadoCentavos;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }
}
