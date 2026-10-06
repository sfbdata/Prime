<?php

declare(strict_types=1);

namespace App\Inteligencia\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * A BlueJus IA de UM escritório: ligada/desligada, cotas e mascaramento de dados pessoais. Uma
 * linha por tenant — a unicidade é uma constraint NOMEADA em `tenant_id` (e não `OneToOne`, cujo
 * índice único nasceria com nome de hash, fora do controle da migration escrita à mão).
 *
 * Ligar a IA registra `consentimentoEnvioExternoEm` + quem ligou: é o "aceite" do escritório para
 * o envio de conteúdo a um suboperador externo (decisão D3 / Anexo I da Política de Privacidade).
 * Desligar não apaga o registro — ele é histórico.
 *
 * Auditável: quem ligou/desligou e mudou cota fica no `audit_log`.
 */
#[ORM\Entity(repositoryClass: ConfiguracaoDeInteligenciaRepository::class)]
#[ORM\Table(name: 'inteligencia_configuracao')]
#[ORM\UniqueConstraint(name: 'uniq_inteligencia_configuracao_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_inteligencia_configuracao_consentimento_por', columns: ['consentimento_por_id'])]
#[ORM\HasLifecycleCallbacks]
class ConfiguracaoDeInteligencia implements TenantAware, Auditavel
{
    public const LIMITE_DIARIO_PADRAO = 50;
    public const LIMITE_MENSAL_PADRAO = 500;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $habilitada = false;

    #[ORM\Column(type: 'integer', options: ['default' => self::LIMITE_DIARIO_PADRAO])]
    private int $limiteDiario = self::LIMITE_DIARIO_PADRAO;

    #[ORM\Column(type: 'integer', options: ['default' => self::LIMITE_MENSAL_PADRAO])]
    private int $limiteMensal = self::LIMITE_MENSAL_PADRAO;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $mascararDadosPessoais = true;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $consentimentoEnvioExternoEm = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $consentimentoPor = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadaEm;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $atualizadaEm = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Tenant $tenant,
    ) {
        $this->criadaEm = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function aoAtualizar(): void
    {
        $this->atualizadaEm = new \DateTimeImmutable();
    }

    /**
     * Aplica o formulário do admin. Ligar (de desligada para ligada) grava o consentimento com
     * quem ligou; religar depois de desligar grava de novo — é o aceite mais recente que vale.
     */
    public function atualizar(
        bool $habilitada,
        int $limiteDiario,
        int $limiteMensal,
        bool $mascararDadosPessoais,
        User $por,
    ): void {
        if ($limiteDiario < 0 || $limiteMensal < 0) {
            throw new \DomainException('Os limites de análises não podem ser negativos.');
        }

        if ($habilitada && !$this->habilitada) {
            $this->consentimentoEnvioExternoEm = new \DateTimeImmutable();
            $this->consentimentoPor = $por;
        }

        $this->habilitada = $habilitada;
        $this->limiteDiario = $limiteDiario;
        $this->limiteMensal = $limiteMensal;
        $this->mascararDadosPessoais = $mascararDadosPessoais;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function isHabilitada(): bool
    {
        return $this->habilitada;
    }

    public function getLimiteDiario(): int
    {
        return $this->limiteDiario;
    }

    public function getLimiteMensal(): int
    {
        return $this->limiteMensal;
    }

    public function isMascararDadosPessoais(): bool
    {
        return $this->mascararDadosPessoais;
    }

    public function getConsentimentoEnvioExternoEm(): ?\DateTimeImmutable
    {
        return $this->consentimentoEnvioExternoEm;
    }

    public function getConsentimentoPor(): ?User
    {
        return $this->consentimentoPor;
    }

    public function getCriadaEm(): \DateTimeImmutable
    {
        return $this->criadaEm;
    }

    public function getAtualizadaEm(): ?\DateTimeImmutable
    {
        return $this->atualizadaEm;
    }
}
