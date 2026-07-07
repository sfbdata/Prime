<?php

declare(strict_types=1);

namespace App\Djen\Entity;

use App\Djen\Repository\PublicacaoDjenRepository;
use App\Entity\Tenant\Tenant;
use App\Processo\Entity\Processo;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma comunicação/publicação processual captada do DJEN (API pública do CNJ) para um escritório.
 *
 * Deduplicada por (tenant_id, djen_id): o "id" da comunicação no DJEN é único por escritório.
 * O item bruto da API é guardado em `payloadDjen` (jsonb) — rede de segurança/auditoria e campos
 * futuros do CNJ ficam disponíveis sem migration, igual ao datajudRaw do Processo.
 *
 * Vínculo com Processo é opcional: quando o número CNJ casa com um Processo do mesmo tenant, a FK é
 * preenchida; caso contrário a publicação fica "avulsa" (processo = null) — nunca se perde intimação.
 */
#[ORM\Entity(repositoryClass: PublicacaoDjenRepository::class)]
#[ORM\Table(name: 'publicacao_djen')]
#[ORM\UniqueConstraint(name: 'uniq_publicacao_djen_tenant_djenid', columns: ['tenant_id', 'djen_id'])]
#[ORM\Index(name: 'idx_publicacao_djen_tenant_data', columns: ['tenant_id', 'data_disponibilizacao'])]
#[ORM\Index(name: 'idx_publicacao_djen_tenant_numero', columns: ['tenant_id', 'numero_processo'])]
#[ORM\HasLifecycleCallbacks]
class PublicacaoDjen implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    /** Id da comunicação no DJEN (chave de deduplicação por tenant). bigint: pode exceder int32. */
    #[ORM\Column(name: 'djen_id', type: 'bigint')]
    private string $djenId = '0';

    /** Número identificador da comunicação (numeroComunicacao na API). */
    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?string $numeroComunicacao = null;

    /** Token opaco para gerar a certidão no DJEN (guardado para uso futuro). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $hash = null;

    #[ORM\Column(length: 20)]
    private string $siglaTribunal = '';

    /** Ex.: "Intimação", "Citação", "Edital"... (texto por extenso vindo da API). */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $tipoComunicacao = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nomeOrgao = null;

    /** Número CNJ em dígitos puros (usado para vincular ao Processo do tenant). */
    #[ORM\Column(length: 50)]
    private string $numeroProcesso = '';

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $numeroProcessoComMascara = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dataDisponibilizacao = null;

    /** "D" (Diário) ou "E" (Edital). */
    #[ORM\Column(length: 1, nullable: true)]
    private ?string $meio = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $meioCompleto = null;

    /** Teor da comunicação — vem em HTML; sanitizar na exibição. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $texto = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $link = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $tipoDocumento = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nomeClasse = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $codigoClasse = null;

    /** OAB monitorada que trouxe a publicação (rastreabilidade da origem). */
    #[ORM\ManyToOne(targetEntity: OabMonitorada::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?OabMonitorada $oabMonitorada = null;

    /** Processo vinculado do mesmo tenant, se cadastrado. Null = publicação avulsa. */
    #[ORM\ManyToOne(targetEntity: Processo::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Processo $processo = null;

    #[ORM\Column(type: 'boolean')]
    private bool $lida = false;

    /**
     * Snapshot bruto do item retornado pela API do DJEN. jsonb para permitir consulta/índice futuro.
     * Preenchido só pelo mapper; nunca por formulário.
     */
    #[ORM\Column(type: 'json', nullable: true, options: ['jsonb' => true])]
    private ?array $payloadDjen = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $atualizadoEm = null;

    public function __construct()
    {
        $this->criadoEm = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function aoAtualizar(): void
    {
        $this->atualizadoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(Tenant $tenant): self
    {
        $this->tenant = $tenant;

        return $this;
    }

    public function getDjenId(): string
    {
        return $this->djenId;
    }

    public function setDjenId(string $djenId): self
    {
        $this->djenId = $djenId;

        return $this;
    }

    public function getNumeroComunicacao(): ?string
    {
        return $this->numeroComunicacao;
    }

    public function setNumeroComunicacao(?string $numeroComunicacao): self
    {
        $this->numeroComunicacao = $numeroComunicacao;

        return $this;
    }

    public function getHash(): ?string
    {
        return $this->hash;
    }

    public function setHash(?string $hash): self
    {
        $this->hash = $hash;

        return $this;
    }

    public function getSiglaTribunal(): string
    {
        return $this->siglaTribunal;
    }

    public function setSiglaTribunal(string $siglaTribunal): self
    {
        $this->siglaTribunal = $siglaTribunal;

        return $this;
    }

    public function getTipoComunicacao(): ?string
    {
        return $this->tipoComunicacao;
    }

    public function setTipoComunicacao(?string $tipoComunicacao): self
    {
        $this->tipoComunicacao = $tipoComunicacao;

        return $this;
    }

    public function getNomeOrgao(): ?string
    {
        return $this->nomeOrgao;
    }

    public function setNomeOrgao(?string $nomeOrgao): self
    {
        $this->nomeOrgao = $nomeOrgao;

        return $this;
    }

    public function getNumeroProcesso(): string
    {
        return $this->numeroProcesso;
    }

    public function setNumeroProcesso(string $numeroProcesso): self
    {
        $this->numeroProcesso = $numeroProcesso;

        return $this;
    }

    public function getNumeroProcessoComMascara(): ?string
    {
        return $this->numeroProcessoComMascara;
    }

    public function setNumeroProcessoComMascara(?string $numeroProcessoComMascara): self
    {
        $this->numeroProcessoComMascara = $numeroProcessoComMascara;

        return $this;
    }

    public function getDataDisponibilizacao(): ?\DateTimeImmutable
    {
        return $this->dataDisponibilizacao;
    }

    public function setDataDisponibilizacao(?\DateTimeImmutable $dataDisponibilizacao): self
    {
        $this->dataDisponibilizacao = $dataDisponibilizacao;

        return $this;
    }

    public function getMeio(): ?string
    {
        return $this->meio;
    }

    public function setMeio(?string $meio): self
    {
        $this->meio = $meio;

        return $this;
    }

    public function getMeioCompleto(): ?string
    {
        return $this->meioCompleto;
    }

    public function setMeioCompleto(?string $meioCompleto): self
    {
        $this->meioCompleto = $meioCompleto;

        return $this;
    }

    public function getTexto(): ?string
    {
        return $this->texto;
    }

    public function setTexto(?string $texto): self
    {
        $this->texto = $texto;

        return $this;
    }

    public function getLink(): ?string
    {
        return $this->link;
    }

    public function setLink(?string $link): self
    {
        $this->link = $link;

        return $this;
    }

    public function getTipoDocumento(): ?string
    {
        return $this->tipoDocumento;
    }

    public function setTipoDocumento(?string $tipoDocumento): self
    {
        $this->tipoDocumento = $tipoDocumento;

        return $this;
    }

    public function getNomeClasse(): ?string
    {
        return $this->nomeClasse;
    }

    public function setNomeClasse(?string $nomeClasse): self
    {
        $this->nomeClasse = $nomeClasse;

        return $this;
    }

    public function getCodigoClasse(): ?string
    {
        return $this->codigoClasse;
    }

    public function setCodigoClasse(?string $codigoClasse): self
    {
        $this->codigoClasse = $codigoClasse;

        return $this;
    }

    public function getOabMonitorada(): ?OabMonitorada
    {
        return $this->oabMonitorada;
    }

    public function setOabMonitorada(?OabMonitorada $oabMonitorada): self
    {
        $this->oabMonitorada = $oabMonitorada;

        return $this;
    }

    public function getProcesso(): ?Processo
    {
        return $this->processo;
    }

    public function setProcesso(?Processo $processo): self
    {
        $this->processo = $processo;

        return $this;
    }

    public function isAvulsa(): bool
    {
        return $this->processo === null;
    }

    public function isLida(): bool
    {
        return $this->lida;
    }

    public function setLida(bool $lida): self
    {
        $this->lida = $lida;

        return $this;
    }

    public function getPayloadDjen(): ?array
    {
        return $this->payloadDjen;
    }

    public function setPayloadDjen(?array $payloadDjen): self
    {
        $this->payloadDjen = $payloadDjen;

        return $this;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getAtualizadoEm(): ?\DateTimeImmutable
    {
        return $this->atualizadoEm;
    }

    /**
     * Destinatários (partes) da comunicação, lidos do payload bruto.
     *
     * @return list<array<string, mixed>>
     */
    public function getDestinatarios(): array
    {
        $destinatarios = $this->payloadDjen['destinatarios'] ?? [];

        return \is_array($destinatarios) ? array_values($destinatarios) : [];
    }
}
