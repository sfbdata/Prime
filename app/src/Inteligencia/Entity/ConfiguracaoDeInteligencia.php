<?php

declare(strict_types=1);

namespace App\Inteligencia\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Enum\ModoDeCobrancaDaIa;
use App\Inteligencia\Enum\VersaoDoAceiteDaIa;
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
 * Liberar é do Master (spec ia-anthropic-ligar §10.1): para o escritório usar a IA, o Master liberou E o admin
 * habilitou com o aceite. O Master também escolhe o modo de cobrança e o orçamento mensal (centavos de dólar; quem faz
 * o orçamento valer é a disponibilidade, a partir do PR C).
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
    /** Teto do orçamento mensal: US$ 100.000,00 em centavos. */
    public const ORCAMENTO_MAXIMO_CENTAVOS = 10_000_000;

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

    /** A versão do texto do aceite ({@see VersaoDoAceiteDaIa}) com que o aceite acima foi dado. */
    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $consentimentoVersao = VersaoDoAceiteDaIa::SemDocumentos->value;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $consentimentoPor = null;

    /** O Master liberou a IA para este escritório (sem isto, o aceite do admin não basta). */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $liberadaPeloMaster = false;

    #[ORM\Column(length: 20, enumType: ModoDeCobrancaDaIa::class, options: ['default' => 'incluso'])]
    private ModoDeCobrancaDaIa $modoDeCobranca = ModoDeCobrancaDaIa::Incluso;

    /** Centavos de dólar por mês; nulo = sem orçamento definido. */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $orcamentoMensalCentavos = null;

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
     * Salvar com a IA ligada quando o aceite é de versão anterior à do texto vigente ({@see VersaoDoAceiteDaIa}) também
     * grava de novo: é a renovação do aceite (o admin salvou vendo o texto novo, que cita os documentos).
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

        if ($habilitada && (!$this->habilitada || $this->consentimentoVersao < VersaoDoAceiteDaIa::atual()->value)) {
            $this->consentimentoEnvioExternoEm = new \DateTimeImmutable();
            $this->consentimentoVersao = VersaoDoAceiteDaIa::atual()->value;
            $this->consentimentoPor = $por;
        }

        $this->habilitada = $habilitada;
        $this->limiteDiario = $limiteDiario;
        $this->limiteMensal = $limiteMensal;
        $this->mascararDadosPessoais = $mascararDadosPessoais;
    }

    public function liberarPeloMaster(): void
    {
        $this->liberadaPeloMaster = true;
    }

    /** Bloquear não mexe no aceite do escritório: ele é histórico e volta a valer se o Master liberar de novo. */
    public function bloquearPeloMaster(): void
    {
        $this->liberadaPeloMaster = false;
    }

    public function definirCobranca(ModoDeCobrancaDaIa $modo, ?int $orcamentoCentavos): void
    {
        if ($orcamentoCentavos !== null && ($orcamentoCentavos < 0 || $orcamentoCentavos > self::ORCAMENTO_MAXIMO_CENTAVOS)) {
            throw new \InvalidArgumentException('O orçamento mensal precisa ficar entre US$ 0,00 e US$ 100.000,00.');
        }

        $this->modoDeCobranca = $modo;
        $this->orcamentoMensalCentavos = $orcamentoCentavos;
    }

    public function isLiberadaPeloMaster(): bool
    {
        return $this->liberadaPeloMaster;
    }

    public function getModoDeCobranca(): ModoDeCobrancaDaIa
    {
        return $this->modoDeCobranca;
    }

    public function getOrcamentoMensalCentavos(): ?int
    {
        return $this->orcamentoMensalCentavos;
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

    /** O administrador deu o aceite do envio ao provedor (qualquer versão do texto)? Base do cruzamento de pastas do chat (PR 4c). */
    public function aceiteDeEnvioDado(): bool
    {
        return $this->consentimentoEnvioExternoEm !== null;
    }

    /** O aceite foi dado com o texto que cita os documentos escolhidos (PR F)? Sem aceite, não. */
    public function aceiteCobreDocumentos(): bool
    {
        return $this->consentimentoEnvioExternoEm !== null && VersaoDoAceiteDaIa::cobreDocumentos($this->consentimentoVersao);
    }

    public function getConsentimentoVersao(): int
    {
        return $this->consentimentoVersao;
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
