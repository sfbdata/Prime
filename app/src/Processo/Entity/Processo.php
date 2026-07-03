<?php

namespace App\Processo\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Processo\Repository\ProcessoRepository;
use App\Shared\Contract\TenantAware;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProcessoRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_processo_tenant_numero', columns: ['tenant_id', 'numero_processo'])]
#[ORM\HasLifecycleCallbacks]
class Processo implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // Unicidade do número é por escritório (UniqueConstraint composto tenant_id+numero_processo).
    #[ORM\Column(length: 50)]
    private string $numeroProcesso = '';

    #[ORM\Column(length: 255)]
    private string $orgaoJulgador = '';

    #[ORM\Column(length: 20)]
    private string $siglaTribunal = '';

    #[ORM\Column(length: 255)]
    private string $classeProcessual = '';

    #[ORM\Column(length: 255)]
    private string $assuntoProcessual = '';

    #[ORM\OneToMany(mappedBy: 'processo', targetEntity: DocumentoProcesso::class, orphanRemoval: true, cascade: ['persist'])]
    private Collection $documentos;

    #[ORM\OneToMany(mappedBy: 'processo', targetEntity: ParteProcesso::class, orphanRemoval: true, cascade: ['persist'])]
    private Collection $partes;

    #[ORM\OneToMany(mappedBy: 'processo', targetEntity: MovimentacaoProcesso::class, orphanRemoval: true, cascade: ['persist'])]
    #[ORM\OrderBy(['dataMovimentacao' => 'DESC', 'id' => 'DESC'])]
    private Collection $movimentacoes;

    #[ORM\OneToMany(mappedBy: 'processo', targetEntity: AssuntoProcesso::class, orphanRemoval: true, cascade: ['persist'])]
    private Collection $assuntos;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $dataDistribuicao = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $dataBaixa = null;

    #[ORM\Column(length: 30)]
    private string $situacaoProcesso = '';

    #[ORM\Column(length: 20)]
    private string $instancia = '';

    // Metadados vindos do Datajud (Base Nacional CNJ). Nullable: processos anteriores à captura
    // ficam null e o preview web efêmero pode não trazer todos os campos.
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $nivelSigilo = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $formato = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $formatoCodigo = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $sistema = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $sistemaCodigo = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $classeCodigo = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $orgaoJulgadorCodigo = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $orgaoJulgadorMunicipioIbge = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $datajudId = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $processoPai = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'processosFilhos')]
    #[ORM\JoinColumn(name: 'processo_pai_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?self $processoPaiRef = null;

    #[ORM\OneToMany(mappedBy: 'processoPaiRef', targetEntity: self::class)]
    private Collection $processosFilhos;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dataAtualizacao = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $criadoAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $modificadoEm = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $criadoPor = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    public function __construct()
    {
        $this->documentos = new ArrayCollection();
        $this->partes = new ArrayCollection();
        $this->movimentacoes = new ArrayCollection();
        $this->assuntos = new ArrayCollection();
        $this->processosFilhos = new ArrayCollection();
        $this->criadoAt = new \DateTimeImmutable();
        $this->modificadoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return Collection<int, DocumentoProcesso>
     */
    public function getDocumentos(): Collection
    {
        return $this->documentos;
    }

    public function addDocumento(DocumentoProcesso $documento): self
    {
        if (!$this->documentos->contains($documento)) {
            $this->documentos->add($documento);
            $documento->setProcesso($this);
        }

        return $this;
    }

    public function removeDocumento(DocumentoProcesso $documento): self
    {
        if ($this->documentos->removeElement($documento)) {
            if ($documento->getProcesso() === $this) {
                $documento->setProcesso(null);
            }
        }

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

    public function getOrgaoJulgador(): string
    {
        return $this->orgaoJulgador;
    }

    public function setOrgaoJulgador(string $orgaoJulgador): self
    {
        $this->orgaoJulgador = mb_strtoupper(trim($orgaoJulgador));
        return $this;
    }

    public function getSiglaTribunal(): string
    {
        return $this->siglaTribunal;
    }

    public function setSiglaTribunal(string $siglaTribunal): self
    {
        $this->siglaTribunal = mb_strtoupper(trim($siglaTribunal));
        return $this;
    }

    public function getClasseProcessual(): string
    {
        return $this->classeProcessual;
    }

    public function setClasseProcessual(string $classeProcessual): self
    {
        $this->classeProcessual = mb_strtoupper(trim($classeProcessual));
        return $this;
    }

    public function getAssuntoProcessual(): string
    {
        return $this->assuntoProcessual;
    }

    public function setAssuntoProcessual(string $assuntoProcessual): self
    {
        $this->assuntoProcessual = mb_strtoupper(trim($assuntoProcessual));
        return $this;
    }

    /**
     * @return Collection<int, ParteProcesso>
     */
    public function getPartes(): Collection
    {
        return $this->partes;
    }

    public function addParte(ParteProcesso $parte): self
    {
        if (!$this->partes->contains($parte)) {
            $this->partes->add($parte);
            $parte->setProcesso($this);
        }

        return $this;
    }

    public function removeParte(ParteProcesso $parte): self
    {
        if ($this->partes->removeElement($parte)) {
            if ($parte->getProcesso() === $this) {
                $parte->setProcesso(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, MovimentacaoProcesso>
     */
    public function getMovimentacoes(): Collection
    {
        return $this->movimentacoes;
    }

    public function addMovimentacao(MovimentacaoProcesso $movimentacao): self
    {
        if (!$this->movimentacoes->contains($movimentacao)) {
            $this->movimentacoes->add($movimentacao);
            $movimentacao->setProcesso($this);
        }

        return $this;
    }

    public function removeMovimentacao(MovimentacaoProcesso $movimentacao): self
    {
        if ($this->movimentacoes->removeElement($movimentacao)) {
            if ($movimentacao->getProcesso() === $this) {
                $movimentacao->setProcesso(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, AssuntoProcesso>
     */
    public function getAssuntos(): Collection
    {
        return $this->assuntos;
    }

    public function addAssunto(AssuntoProcesso $assunto): self
    {
        if (!$this->assuntos->contains($assunto)) {
            $this->assuntos->add($assunto);
            $assunto->setProcesso($this);
        }

        return $this;
    }

    public function removeAssunto(AssuntoProcesso $assunto): self
    {
        if ($this->assuntos->removeElement($assunto)) {
            if ($assunto->getProcesso() === $this) {
                $assunto->setProcesso(null);
            }
        }

        return $this;
    }

    public function getDataDistribuicao(): ?\DateTimeInterface
    {
        return $this->dataDistribuicao;
    }

    public function setDataDistribuicao(?\DateTimeInterface $dataDistribuicao): self
    {
        $this->dataDistribuicao = $dataDistribuicao;
        return $this;
    }

    public function getDataBaixa(): ?\DateTimeInterface
    {
        return $this->dataBaixa;
    }

    public function setDataBaixa(?\DateTimeInterface $dataBaixa): self
    {
        $this->dataBaixa = $dataBaixa;
        return $this;
    }

    public function getSituacaoProcesso(): string
    {
        return $this->situacaoProcesso;
    }

    public function setSituacaoProcesso(string $situacaoProcesso): self
    {
        $this->situacaoProcesso = mb_strtoupper(trim($situacaoProcesso));
        return $this;
    }

    public function getInstancia(): string
    {
        return $this->instancia;
    }

    public function setInstancia(string $instancia): self
    {
        $this->instancia = mb_strtoupper(trim($instancia));
        return $this;
    }

    public function getNivelSigilo(): ?int
    {
        return $this->nivelSigilo;
    }

    public function setNivelSigilo(?int $nivelSigilo): self
    {
        $this->nivelSigilo = $nivelSigilo;
        return $this;
    }

    // 0 = público; qualquer valor maior indica segredo de justiça (níveis MNI/CNJ).
    public function getNivelSigiloLabel(): ?string
    {
        if ($this->nivelSigilo === null) {
            return null;
        }

        return $this->nivelSigilo === 0 ? 'Público' : 'Segredo de justiça';
    }

    public function getFormato(): ?string
    {
        return $this->formato;
    }

    public function setFormato(?string $formato): self
    {
        $this->formato = $formato !== null ? trim($formato) : null;
        return $this;
    }

    public function getFormatoCodigo(): ?int
    {
        return $this->formatoCodigo;
    }

    public function setFormatoCodigo(?int $formatoCodigo): self
    {
        $this->formatoCodigo = $formatoCodigo;
        return $this;
    }

    public function getSistema(): ?string
    {
        return $this->sistema;
    }

    public function setSistema(?string $sistema): self
    {
        $this->sistema = $sistema !== null ? trim($sistema) : null;
        return $this;
    }

    public function getSistemaCodigo(): ?int
    {
        return $this->sistemaCodigo;
    }

    public function setSistemaCodigo(?int $sistemaCodigo): self
    {
        $this->sistemaCodigo = $sistemaCodigo;
        return $this;
    }

    public function getClasseCodigo(): ?int
    {
        return $this->classeCodigo;
    }

    public function setClasseCodigo(?int $classeCodigo): self
    {
        $this->classeCodigo = $classeCodigo;
        return $this;
    }

    public function getOrgaoJulgadorCodigo(): ?string
    {
        return $this->orgaoJulgadorCodigo;
    }

    public function setOrgaoJulgadorCodigo(?string $orgaoJulgadorCodigo): self
    {
        $this->orgaoJulgadorCodigo = $orgaoJulgadorCodigo !== null ? trim($orgaoJulgadorCodigo) : null;
        return $this;
    }

    public function getOrgaoJulgadorMunicipioIbge(): ?int
    {
        return $this->orgaoJulgadorMunicipioIbge;
    }

    public function setOrgaoJulgadorMunicipioIbge(?int $orgaoJulgadorMunicipioIbge): self
    {
        $this->orgaoJulgadorMunicipioIbge = $orgaoJulgadorMunicipioIbge;
        return $this;
    }

    public function getDatajudId(): ?string
    {
        return $this->datajudId;
    }

    public function setDatajudId(?string $datajudId): self
    {
        $this->datajudId = $datajudId !== null ? trim($datajudId) : null;
        return $this;
    }

    public function getProcessoPai(): ?string
    {
        return $this->processoPai;
    }

    public function setProcessoPai(?string $processoPai): self
    {
        $this->processoPai = $processoPai;

        if ($this->processoPaiRef !== null && $this->processoPaiRef->getNumeroProcesso() !== $processoPai) {
            $this->processoPaiRef = null;
        }

        return $this;
    }

    public function getProcessoPaiRef(): ?self
    {
        return $this->processoPaiRef;
    }

    public function setProcessoPaiRef(?self $processoPaiRef): self
    {
        $this->processoPaiRef = $processoPaiRef;
        $this->processoPai = $processoPaiRef?->getNumeroProcesso();

        return $this;
    }

    /**
     * @return Collection<int, self>
     */
    public function getProcessosFilhos(): Collection
    {
        return $this->processosFilhos;
    }

    public function addProcessoFilho(self $processoFilho): self
    {
        if (!$this->processosFilhos->contains($processoFilho)) {
            $this->processosFilhos->add($processoFilho);
            $processoFilho->setProcessoPaiRef($this);
        }

        return $this;
    }

    public function removeProcessoFilho(self $processoFilho): self
    {
        if ($this->processosFilhos->removeElement($processoFilho)) {
            if ($processoFilho->getProcessoPaiRef() === $this) {
                $processoFilho->setProcessoPaiRef(null);
            }
        }

        return $this;
    }

    public function getDataAtualizacao(): ?\DateTimeImmutable
    {
        return $this->dataAtualizacao;
    }

    public function setDataAtualizacao(?\DateTimeImmutable $dataAtualizacao): self
    {
        $this->dataAtualizacao = $dataAtualizacao;
        return $this;
    }

    #[ORM\PreUpdate]
    public function setModificadoEmValue(): void
    {
        $this->modificadoEm = new \DateTimeImmutable();
    }

    public function getCriadoAt(): ?\DateTimeImmutable
    {
        return $this->criadoAt;
    }

    public function setCriadoAt(?\DateTimeImmutable $criadoAt): self
    {
        $this->criadoAt = $criadoAt;
        return $this;
    }

    public function getModificadoEm(): ?\DateTimeImmutable
    {
        return $this->modificadoEm;
    }

    public function setModificadoEm(?\DateTimeImmutable $modificadoEm): self
    {
        $this->modificadoEm = $modificadoEm;
        return $this;
    }

    public function getCriadoPor(): ?User
    {
        return $this->criadoPor;
    }

    public function setCriadoPor(?User $criadoPor): self
    {
        $this->criadoPor = $criadoPor;
        return $this;
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

}
