<?php

namespace App\Entity\Processo;

use App\Entity\Contrato\Contrato;
use App\Entity\Tarefa\Tarefa;
use App\Repository\ProcessoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProcessoRepository::class)]
class Processo
{
    public const STATUS_DOCUMENTOS_PENDENTE = 'PENDENTE_DE_DOCUMENTACAO';
    public const STATUS_DOCUMENTOS_APTO = 'APTO_PARA_PROTOCOLAR';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true)]
    private string $numeroProcesso = '';

    #[ORM\Column(length: 255)]
    private string $orgaoJulgador = '';

    #[ORM\Column(length: 20)]
    private string $siglaTribunal = '';

    #[ORM\Column(length: 255)]
    private string $classeProcessual = '';

    #[ORM\Column(length: 255)]
    private string $assuntoProcessual = '';

    #[ORM\OneToMany(mappedBy: 'processo', targetEntity: ParteProcesso::class, orphanRemoval: true, cascade: ['persist'])]
    private Collection $partes;

    #[ORM\OneToMany(mappedBy: 'processo', targetEntity: MovimentacaoProcesso::class, orphanRemoval: true, cascade: ['persist'])]
    #[ORM\OrderBy(['dataMovimentacao' => 'DESC', 'id' => 'DESC'])]
    private Collection $movimentacoes;

    #[ORM\OneToMany(mappedBy: 'processo', targetEntity: DocumentoProcesso::class, orphanRemoval: true, cascade: ['persist', 'remove'])]
    private Collection $documentos;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $dataDistribuicao = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $dataBaixa = null;

    #[ORM\Column(length: 30)]
    private string $situacaoProcesso = '';

    #[ORM\Column(length: 20)]
    private string $instancia = '';

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $processoPai = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'processosFilhos')]
    #[ORM\JoinColumn(name: 'processo_pai_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?self $processoPaiRef = null;

    #[ORM\OneToMany(mappedBy: 'processoPaiRef', targetEntity: self::class)]
    private Collection $processosFilhos;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dataAtualizacao = null;

    #[ORM\ManyToOne(targetEntity: Contrato::class, inversedBy: 'processos')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Contrato $contrato = null;

    #[ORM\OneToMany(mappedBy: 'processo', targetEntity: Tarefa::class)]
    private Collection $tarefas;

    #[ORM\Column(options: ['default' => false])]
    private bool $docPecaOk = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $docProcuracaoOk = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $docIdentificacaoOk = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $docComprovanteResidenciaOk = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $docGratuidadeJusticaOk = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $docDemaisOk = false;

    #[ORM\Column(length: 40)]
    private string $statusDocumentos = self::STATUS_DOCUMENTOS_PENDENTE;

    public function __construct()
    {
        $this->partes = new ArrayCollection();
        $this->movimentacoes = new ArrayCollection();
        $this->documentos = new ArrayCollection();
        $this->processosFilhos = new ArrayCollection();
        $this->tarefas = new ArrayCollection();
        $this->statusDocumentos = self::STATUS_DOCUMENTOS_PENDENTE;
    }

    public function getId(): ?int
    {
        return $this->id;
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
        $this->orgaoJulgador = $orgaoJulgador;
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

    public function getClasseProcessual(): string
    {
        return $this->classeProcessual;
    }

    public function setClasseProcessual(string $classeProcessual): self
    {
        $this->classeProcessual = $classeProcessual;
        return $this;
    }

    public function getAssuntoProcessual(): string
    {
        return $this->assuntoProcessual;
    }

    public function setAssuntoProcessual(string $assuntoProcessual): self
    {
        $this->assuntoProcessual = $assuntoProcessual;
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
        $this->situacaoProcesso = $situacaoProcesso;
        return $this;
    }

    public function getInstancia(): string
    {
        return $this->instancia;
    }

    public function setInstancia(string $instancia): self
    {
        $this->instancia = $instancia;
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

    public function getContrato(): ?Contrato
    {
        return $this->contrato;
    }

    public function setContrato(?Contrato $contrato): self
    {
        $this->contrato = $contrato;
        return $this;
    }

    /**
     * @return Collection<int, Tarefa>
     */
    public function getTarefas(): Collection
    {
        return $this->tarefas;
    }

    public function isDocPecaOk(): bool
    {
        return $this->docPecaOk;
    }

    public function setDocPecaOk(bool $docPecaOk): self
    {
        $this->docPecaOk = $docPecaOk;
        $this->recalculateStatusDocumentos();

        return $this;
    }

    public function isDocProcuracaoOk(): bool
    {
        return $this->docProcuracaoOk;
    }

    public function setDocProcuracaoOk(bool $docProcuracaoOk): self
    {
        $this->docProcuracaoOk = $docProcuracaoOk;
        $this->recalculateStatusDocumentos();

        return $this;
    }

    public function isDocIdentificacaoOk(): bool
    {
        return $this->docIdentificacaoOk;
    }

    public function setDocIdentificacaoOk(bool $docIdentificacaoOk): self
    {
        $this->docIdentificacaoOk = $docIdentificacaoOk;
        $this->recalculateStatusDocumentos();

        return $this;
    }

    public function isDocComprovanteResidenciaOk(): bool
    {
        return $this->docComprovanteResidenciaOk;
    }

    public function setDocComprovanteResidenciaOk(bool $docComprovanteResidenciaOk): self
    {
        $this->docComprovanteResidenciaOk = $docComprovanteResidenciaOk;
        $this->recalculateStatusDocumentos();

        return $this;
    }

    public function isDocGratuidadeJusticaOk(): bool
    {
        return $this->docGratuidadeJusticaOk;
    }

    public function setDocGratuidadeJusticaOk(bool $docGratuidadeJusticaOk): self
    {
        $this->docGratuidadeJusticaOk = $docGratuidadeJusticaOk;
        $this->recalculateStatusDocumentos();

        return $this;
    }

    public function isDocDemaisOk(): bool
    {
        return $this->docDemaisOk;
    }

    public function setDocDemaisOk(bool $docDemaisOk): self
    {
        $this->docDemaisOk = $docDemaisOk;
        $this->recalculateStatusDocumentos();

        return $this;
    }

    public function getStatusDocumentos(): string
    {
        return $this->statusDocumentos;
    }

    public function setStatusDocumentos(string $statusDocumentos): self
    {
        $this->statusDocumentos = $statusDocumentos;

        return $this;
    }

    private function recalculateStatusDocumentos(): void
    {
        $allChecked = $this->docPecaOk
            && $this->docProcuracaoOk
            && $this->docIdentificacaoOk
            && $this->docComprovanteResidenciaOk
            && $this->docGratuidadeJusticaOk
            && $this->docDemaisOk;

        $this->statusDocumentos = $allChecked
            ? self::STATUS_DOCUMENTOS_APTO
            : self::STATUS_DOCUMENTOS_PENDENTE;
    }
}
